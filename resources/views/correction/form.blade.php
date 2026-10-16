<!DOCTYPE html>
<html>

<head>
    <title>Update Details</title>
</head>

<body>
    <h3>Update Your Name & DOB</h3>
    <p>Request #{{ $req->id }} — {{ $req->user->email }}</p>

    @if ($errors->any())
        <p style="color:red">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('correction.update', $token) }}">
        @csrf
        <label>Full Name</label><br>
        <input type="text" name="full_name" value="{{ old('full_name', $req->full_name) }}" required><br><br>

        <label>Date of Birth</label><br>
        <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $req->date_of_birth->format('Y-m-d')) }}"
            required><br><br>

        <button type="submit">Save</button>
    </form>
</body>

</html>
