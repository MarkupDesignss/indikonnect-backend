<!DOCTYPE html>
<html>

<head>
    <title>Update Details</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>

<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
    <div
        style="max-width:480px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <div style="background:#7c3aed;color:#fff;padding:18px 24px;text-align:center;">
            <img src="https://www.markupdesigns.net/indikonnect/storage/logos/MmVFOudCiVU3Y8C0sf2gEEXna5jSKroCDj8xYMo0.png"
                alt="Logo" style="max-height:44px;display:block;margin:0 auto 10px;">
            <h3 style="margin:0;font-size:17px;">
                @if ($req->needsNameUpdate() && $req->needsDobUpdate())
                    Update Your Name &amp; DOB
                @elseif ($req->needsNameUpdate())
                    Update Your Name
                @elseif ($req->needsDobUpdate())
                    Update Your Date of Birth
                @endif
            </h3>
        </div>

        <div style="padding:24px;color:#333;font-size:14px;line-height:1.6;">
            <p style="margin-top:0;">Request #{{ $req->id }} — {{ $req->user->email }}</p>

            <p style="color:#7c3aed;font-weight:bold;margin-top:-6px;">
                Reason: {{ $req->reason_label }}
            </p>

            @if ($errors->any())
                <p style="color:red">{{ $errors->first() }}</p>
            @endif

            <form method="POST" action="{{ route('correction.update', $token) }}" id="correctionForm">
                @csrf

                @if ($req->needsNameUpdate())
                    <label style="font-weight:bold;">Full Name</label><br>
                    <input type="text" name="full_name" id="full_name"
                        value="{{ old('full_name', $req->full_name ?? $req->user->full_name) }}" required
                        style="width:100%;padding:10px;margin:6px 0 16px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;"><br>
                @endif

                @if ($req->needsDobUpdate())
                    <label style="font-weight:bold;">Date of Birth</label><br>
                    <input type="date" name="date_of_birth" id="date_of_birth"
                        value="{{ old('date_of_birth', optional($req->date_of_birth ?? $req->user->date_of_birth)->format('Y-m-d')) }}"
                        required
                        style="width:100%;padding:10px;margin:6px 0 20px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;"><br>
                @endif

                <button type="submit" id="saveBtn"
                    style="background:#16a34a;color:#fff;padding:12px 24px;border:none;border-radius:6px;font-weight:bold;cursor:pointer;width:100%;">Save</button>
            </form>
        </div>

        <div style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#888;text-align:center;">
            <a href="{{ config('app.frontend_url', env('FRONTEND_URL')) }}"
                style="color:#2563eb;text-decoration:none;">{{ config('app.frontend_url', env('FRONTEND_URL')) }}</a>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
        <div
            style="background:#fff;border-radius:8px;max-width:420px;width:100%;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,0.2);">
            <div style="background:#7c3aed;color:#fff;padding:16px 20px;">
                <h4 style="margin:0;font-size:16px;">⚠️ Confirm Your Update</h4>
            </div>
            <div style="padding:20px;color:#333;font-size:14px;line-height:1.6;">
                <p style="margin-top:0;">Please review the changes before saving. Once submitted, <strong>this link
                        will be invalid</strong> and the details cannot be edited again.</p>

                <div
                    style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;margin:16px 0;">
                    @if ($req->needsNameUpdate())
                        <p style="margin:0 0 6px;"><strong>New Name:</strong> <span id="previewName"></span></p>
                    @endif
                    @if ($req->needsDobUpdate())
                        <p style="margin:0;"><strong>New DOB:</strong> <span id="previewDob"></span></p>
                    @endif
                </div>

                <p style="margin-bottom:0;color:#666;font-size:13px;">Are you sure you want to proceed?</p>
            </div>
            <div style="padding:16px 20px;background:#f9fafb;display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" id="cancelBtn"
                    style="background:#e5e7eb;color:#333;padding:10px 18px;border:none;border-radius:6px;font-weight:bold;cursor:pointer;">Cancel</button>
                <button type="button" id="confirmBtn"
                    style="background:#16a34a;color:#fff;padding:10px 18px;border:none;border-radius:6px;font-weight:bold;cursor:pointer;">Yes,
                    Save</button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const form = document.getElementById('correctionForm');
            const modal = document.getElementById('confirmModal');
            const nameInput = document.getElementById('full_name');
            const dobInput = document.getElementById('date_of_birth');
            const previewName = document.getElementById('previewName');
            const previewDob = document.getElementById('previewDob');
            const cancelBtn = document.getElementById('cancelBtn');
            const confirmBtn = document.getElementById('confirmBtn');

            let confirmed = false;

            form.addEventListener('submit', function(e) {
                if (confirmed) return;

                e.preventDefault();

                // Validate only visible fields
                if (nameInput && !nameInput.value.trim()) {
                    alert('Please enter Full Name.');
                    return;
                }
                if (dobInput && !dobInput.value) {
                    alert('Please select Date of Birth.');
                    return;
                }

                // Preview
                if (previewName && nameInput) {
                    previewName.textContent = nameInput.value.trim();
                }

                if (previewDob && dobInput) {
                    const d = new Date(dobInput.value);
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov',
                        'Dec'
                    ];
                    previewDob.textContent = isNaN(d.getTime()) ?
                        dobInput.value :
                        `${String(d.getDate()).padStart(2,'0')} ${months[d.getMonth()]} ${d.getFullYear()}`;
                }

                modal.style.display = 'flex';
            });

            cancelBtn.addEventListener('click', function() {
                modal.style.display = 'none';
            });

            confirmBtn.addEventListener('click', function() {
                confirmed = true;
                modal.style.display = 'none';
                form.submit();
            });

            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') {
                    modal.style.display = 'none';
                }
            });
        })();
    </script>
</body>

</html>
