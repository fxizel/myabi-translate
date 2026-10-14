<?php

namespace App\Services\BrowserExtension;

use App\Domain\Devconf\FormatRegistry;
use App\Models\PersonalAccessToken;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class FeedbackService
{
    public function decorate(User $user, array $response): array
    {
        $response['feedbackAllowed'] = $this->allowed($user);
        if (! $response['feedbackAllowed'] || $response['results'] === []) {
            return $response;
        }
        $terms = Term::visibleTo($user)->where('obsolete', false)
            ->whereIn('id', array_column($response['results'], 'termId'))->get()->keyBy('id');
        foreach ($response['results'] as &$result) {
            $term = $terms->get($result['termId']);
            if (! $term || $term->type !== $result['type'] || $term->key !== $result['keyParts']) {
                throw new ApiProblem(503, 'search_index_unavailable');
            }
            $payload = [
                'v' => 2, 'userId' => $user->id, 'tokenId' => $user->currentAccessToken()->getKey(),
                'termId' => $term->id, 'type' => $term->type,
                'identityHash' => $this->identityHash($term),
                'attribute' => $result['attribute'], 'language' => $result['targetLanguage'],
                'revision' => (int) $term->revision_no, 'sourceHash' => $this->sourceHash($term),
                'nonce' => bin2hex(random_bytes(16)), 'exp' => now()->addMinutes(15)->timestamp,
            ];
            $encoded = $this->encode(json_encode($payload, JSON_THROW_ON_ERROR));
            $result['feedbackProof'] = $encoded.'.'.$this->signature($encoded);
        }

        return $response;
    }

    public function submit(User $user, array $data): void
    {
        if (! $this->allowed($user)) {
            throw new ApiProblem(403, 'feedback_not_allowed');
        }
        $parts = explode('.', $data['feedbackProof']);
        if (count($parts) !== 2 || ! hash_equals($this->signature($parts[0]), $parts[1])) {
            throw new ApiProblem(404, 'not_found');
        }
        try {
            $payload = json_decode($this->decode($parts[0]), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiProblem(404, 'not_found');
        }
        if (! $this->validPayload($payload, $user)) {
            throw new ApiProblem(404, 'not_found');
        }
        if ($payload['exp'] <= now()->timestamp) {
            throw new ApiProblem(409, 'result_stale');
        }
        $cellId = 'cell2.'.$this->encode(json_encode([$payload['termId'], $payload['attribute'], $payload['language']], JSON_THROW_ON_ERROR));
        if (! hash_equals($cellId, $data['resultId'])) {
            throw new ApiProblem(404, 'not_found');
        }
        DB::transaction(function () use ($user, $payload, $data) {
            // Match the profile's lock order and recheck after any lock wait:
            // a valid request may have been revoked while it was in flight.
            $freshUser = User::whereKey($user->id)->lockForUpdate()->first();
            $token = PersonalAccessToken::whereKey($payload['tokenId'])
                ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->lockForUpdate()->first();
            if (! $freshUser || ! $token || $token->revoked_at || ! $token->expires_at
                || $token->expires_at->lessThanOrEqualTo(now())
                || $token->session_version !== (int) $freshUser->session_version
                || ! $freshUser->isLoginAllowed() || ! $freshUser->canSeeCatalogue()) {
                throw new ApiProblem(401, 'unauthenticated');
            }
            if (! $token->hasCurrentMfaProof($freshUser)) {
                throw new ApiProblem(403, 'mfa_required');
            }
            $freshUser->withAccessToken($token);
            if (! $this->allowed($freshUser)) {
                throw new ApiProblem(403, 'feedback_not_allowed');
            }
            $user = $freshUser;
            $term = Term::visibleTo($user)->where('obsolete', false)->lockForUpdate()->find($payload['termId']);
            if (! $term || $term->type !== $payload['type']
                || ! hash_equals($this->identityHash($term), $payload['identityHash'])
                || (int) $term->revision_no !== $payload['revision']
                || ! hash_equals($this->sourceHash($term), $payload['sourceHash'])) {
                throw new ApiProblem(409, 'result_stale');
            }
            if (! in_array($payload['attribute'], FormatRegistry::get($term->type)->attributeNames, true)) {
                throw new ApiProblem(404, 'not_found');
            }
            if ($payload['exp'] <= now()->timestamp) {
                throw new ApiProblem(409, 'result_stale');
            }
            $digest = hash('sha256', $data['feedbackProof']);
            if (DB::table('browser_extension_feedback')->where('proof_digest', $digest)->exists()) {
                return;
            }
            DB::table('browser_extension_feedback')->insertOrIgnore([
                'user_id' => $user->id, 'organization_id' => $user->organization_id,
                'token_id' => $user->currentAccessToken()->getKey(), 'term_id' => $term->id,
                'type' => $term->type, 'attribute' => $payload['attribute'], 'language' => $payload['language'],
                'revision_no' => $payload['revision'], 'verdict' => 'correct',
                'proof_digest' => $digest, 'created_at' => now(),
            ]);
        }, 3);
    }

    public function allowed(User $user): bool
    {
        $token = $user->currentAccessToken();

        return (bool) config('browser-extension.feedback_enabled', true)
            && $user->canSeeCatalogue() && $token !== null
            && in_array('extension:feedback', $token->abilities ?? [], true);
    }

    private function validPayload(mixed $payload, User $user): bool
    {
        if (! is_array($payload) || ($payload['v'] ?? null) !== 2
            || ($payload['userId'] ?? null) !== $user->id
            || ($payload['tokenId'] ?? null) !== $user->currentAccessToken()->getKey()) {
            return false;
        }
        foreach (['termId', 'revision', 'exp'] as $field) {
            if (! isset($payload[$field]) || ! is_int($payload[$field]) || $payload[$field] < 1) {
                return false;
            }
        }
        foreach (['type', 'attribute', 'language', 'identityHash', 'sourceHash', 'nonce'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                return false;
            }
        }

        return isset(FormatRegistry::all()[$payload['type']])
            && in_array($payload['language'], ['de', 'fr', 'it', 'en'], true)
            && preg_match('/\A[a-f0-9]{64}\z/', $payload['identityHash']) === 1
            && preg_match('/\A[a-f0-9]{64}\z/', $payload['sourceHash']) === 1
            && preg_match('/\A[a-f0-9]{32}\z/', $payload['nonce']) === 1;
    }

    private function identityHash(Term $term): string
    {
        return hash('sha256', json_encode([$term->type, $term->key], JSON_THROW_ON_ERROR));
    }

    private function sourceHash(Term $term): string
    {
        return hash('sha256', (string) $term->source_hash);
    }

    private function signature(string $encoded): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new ApiProblem(503, 'internal_error');
        }
        $signingKey = hash_hmac('sha256', 'browser-extension-feedback-v2', $key, true);

        return $this->encode(hash_hmac('sha256', $encoded, $signingKey, true));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            throw new ApiProblem(404, 'not_found');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || $this->encode($decoded) !== $value) {
            throw new ApiProblem(404, 'not_found');
        }

        return $decoded;
    }
}
