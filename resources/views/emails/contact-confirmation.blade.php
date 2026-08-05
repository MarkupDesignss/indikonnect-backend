<!-- resources/views/emails/contact-confirmation.blade.php -->
<!DOCTYPE html>
<html>

<head>
    <title>Thank You for Contacting Us</title>
</head>

<body>
    <h2>Hello {{ $contact->name }},</h2>
    <p>Thank you for contacting us. We have received your message and will get back to you shortly.</p>

    <h3>Your Message:</h3>
    <p>{{ $contact->message }}</p>

    <p>Best regards,<br>{{ config('app.name') }} Team</p>
</body>

</html>
