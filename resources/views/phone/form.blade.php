<!DOCTYPE html>
<html>
<head><title>Update Phone Number</title>
<style>
    body{font-family:sans-serif;max-width:420px;margin:80px auto;padding:20px}
    input,button{width:100%;padding:10px;margin:8px 0;box-sizing:border-box}
    .error{color:red;font-size:14px}
</style>
</head>
<body>
    <h2>Update Your Phone Number</h2>

    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ url("/phone-update/{$token}") }}">
        @csrf
        <label>New Phone Number</label>
        <input type="text" name="phone" value="{{ old('phone') }}" required>
        <button type="submit">Update</button>
    </form>
</body>
</html>
