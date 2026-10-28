{{-- resources/views/emails/phone-update-link.blade.php --}}
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Update Your Phone Number</title>
</head>

<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,sans-serif">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:40px 0">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0"
                    style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05)">
                    <tr>
                        <td style="background:#2563eb;padding:24px;text-align:center">
                            <h1 style="color:#ffffff;margin:0;font-size:20px">Update Your Phone Number</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 40px;color:#374151;font-size:15px;line-height:1.6">
                            <p style="margin-top:0">Hello <strong>{{ $userName }}</strong>,</p>

                            <p>Aapki phone number change request approve ho gayi hai.
                                Neeche diye button pe click karke apna naya phone number set karein.</p>

                            <table cellpadding="0" cellspacing="0" style="margin:24px 0">
                                <tr>
                                    <td align="center" style="border-radius:6px;background:#2563eb">
                                        <a href="{{ $link }}"
                                            style="display:inline-block;padding:12px 32px;color:#ffffff;
                                                  text-decoration:none;font-weight:bold;font-size:15px">
                                            Update Phone Number
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size:13px;color:#6b7280">
                                ⏰ Ye link <strong>{{ $expiresInMinutes }} minutes</strong> me expire ho jayega aur
                                sirf ek baar use ho sakta hai.
                            </p>

                            <p style="font-size:13px;color:#6b7280;margin-bottom:0">
                                Agar button kaam na kare, to ye link copy karein:<br>
                                <a href="{{ $link }}"
                                    style="color:#2563eb;word-break:break-all">{{ $link }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td
                            style="background:#f9fafb;padding:16px 40px;text-align:center;
                                   font-size:12px;color:#9ca3af">
                            Agar aapne ye request nahi ki, to is email ko ignore karein.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
