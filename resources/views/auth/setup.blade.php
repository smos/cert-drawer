@extends('layouts.app')

@section('content')
<div style="max-width: 500px; margin: 60px auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <h1 style="text-align: center; margin-bottom: 10px;">Welcome to Cert Drawer</h1>
    <p style="text-align: center; color: #666; margin-bottom: 30px;">Create your initial administrator account to get started.</p>
    
    @if($errors->any())
        <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
            <ul style="margin: 0; padding-left: 20px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('setup.store') }}" method="POST">
        @csrf
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Full Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required autofocus style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px;">
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Email Address</label>
            <input type="email" name="email" value="{{ old('email') }}" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px;">
            <small style="color: #888;">This will be your local admin login.</small>
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Password</label>
            <input type="password" name="password" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px;">
            <small style="color: #888;">Minimum 8 characters.</small>
        </div>

        <div style="margin-bottom: 30px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Confirm Password</label>
            <input type="password" name="password_confirmation" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px;">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 1.1rem;">Finish Setup</button>
    </form>
</div>
@endsection
