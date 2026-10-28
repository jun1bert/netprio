<x-guest-layout>
    <h1 class="text-lg font-semibold text-gray-900">{{ __('Access restricted') }}</h1>
    <p class="mt-4 text-gray-600">{{ __('Your account does not have permission to access this page. If you are awaiting access, contact your institution administrator for a role assignment.') }}</p>
    @auth
        <a class="inline-block mt-4 underline text-gray-700" href="{{ route('profile.edit') }}">{{ __('Manage your profile') }}</a>
        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <x-secondary-button type="submit">{{ __('Log out') }}</x-secondary-button>
        </form>
    @else
        <a class="inline-block mt-4 underline text-gray-700" href="{{ route('login') }}">{{ __('Log in') }}</a>
    @endauth
</x-guest-layout>