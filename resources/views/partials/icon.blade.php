<svg class="ui-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
    @case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>@break
    @case('home')<path d="m3 10 9-7 9 7M5 9v12h5v-7h4v7h5V9"/>@break
    @case('user')<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>@break
    @case('help')<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 4 2c-1.5 1-1.5 1-1.5 3M12 17h.01"/>@break
    @case('grid')<rect x="3" y="3" width="4" height="4"/><rect x="10" y="3" width="4" height="4"/><rect x="17" y="3" width="4" height="4"/><rect x="3" y="10" width="4" height="4"/><rect x="10" y="10" width="4" height="4"/><rect x="17" y="10" width="4" height="4"/><rect x="3" y="17" width="4" height="4"/><rect x="10" y="17" width="4" height="4"/><rect x="17" y="17" width="4" height="4"/>@break
    @case('languages')<path d="M3 5h12M9 3v2M6 5c0 5 3 8 7 10M12 5c0 5-4 9-9 11m10 5 4-10 4 10m-6.5-4h5"/>@break
    @case('check-list')<path d="m3 6 2 2 4-4m-6 9 2 2 4-4m-6 9 2 2 4-4M12 6h9M12 13h9M12 20h9"/>@break
    @case('upload')<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 0v6h6M12 18v-7m-3 3 3-3 3 3"/>@break
    @case('download')<path d="M12 3v12m-4-4 4 4 4-4M4 16v5h16v-5"/>@break
    @case('settings')<path d="m10 3-1 3-3 1-2 3 2 2-1 3 3 3 3-1 2 2 3-2 1-3 3-1v-4l-3-1-1-3-4-1Z"/><circle cx="12" cy="11" r="3"/>@break
    @case('history')<path d="M3 11a9 9 0 1 1 2 7M3 4v7h7m2-5v6l4 2"/>@break
    @case('rows')<rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/>@break
    @case('close')<path d="m6 6 12 12M6 18 18 6"/>@break
@endswitch
</svg>
