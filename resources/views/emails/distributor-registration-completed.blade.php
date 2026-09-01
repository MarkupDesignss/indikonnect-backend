<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Distributor Registration Completed</title>
</head>
<body>

    <h2>Distributor Registration Completed Successfully</h2>

    <p>Hello {{ $user->full_name }},</p>

    <p>
        Congratulations! Your distributor registration has been completed successfully.
    </p>

    <p>
        Your account is currently waiting for <strong>Admin KYC Verification</strong>.
        Once your KYC documents are reviewed and approved, your distributor account
        will be activated.
    </p>

    <p>
        You will be notified once the verification process is completed.
    </p>

    <br>

    <p>
        Thank you,<br>
        <strong>{{ config('app.name') }}</strong>
    </p>

</body>
</html>
