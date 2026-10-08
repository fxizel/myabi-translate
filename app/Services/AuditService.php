<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditService
{
    private const PRIVATE_FIELDS = ['current_password', 'password', 'password_confirmation', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'invitation_token', 'token', 'code', 'recovery_code'];

    public function record(string $action, Model|string|null $entity = null, array $before = [], array $after = [], ?User $user = null): AuditEvent
    {
        $user ??= auth()->user();
        $request = app()->bound('request') ? request() : null;
        $requestId = $request?->attributes->get('request_id');
        if (! $requestId) {
            $requestId = (string) Str::uuid();
            $request?->attributes->set('request_id', $requestId);
        }

        return AuditEvent::create([
            'created_at' => now(), 'user_id' => $user?->id, 'organization_id' => $user?->organization_id,
            'action' => $action, 'entity_type' => $entity instanceof Model ? $entity->getMorphClass() : $entity,
            'entity_id' => $entity instanceof Model ? (string) $entity->getKey() : null,
            'before_values' => $this->redact($before), 'after_values' => $this->redact($after),
            'ip_address' => $request?->ip(), 'request_id' => $requestId,
        ]);
    }

    private function redact(array $values): array
    {
        foreach ($values as $key => &$value) {
            if (in_array(strtolower((string) $key), self::PRIVATE_FIELDS, true)) {
                $value = '[redacted]';
            } elseif (is_array($value)) {
                $value = $this->redact($value);
            }
        }

        return $values;
    }
}
