<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactConfirmation;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    // Store new contact message
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:20',
                'message' => 'required|string|min:10|max:5000',
            ]);
            $contact = Contact::create($data);

            Mail::to($contact->email)->send(new ContactConfirmation($contact));

            return response()->json([
                'success' => true,
                'message' => 'Your message has been sent successfully!',
                'data' => $contact
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message. Please try again later.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Get all contacts (Admin only)
    public function index(Request $request)
    {
        $contacts = Contact::query()
            ->when($request->has('unread'), function ($query) {
                return $query->unread();
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $contacts
        ]);
    }

    // Get single contact
    public function show($id)
    {
        $contact = Contact::findOrFail($id);

        // Mark as read when viewed
        if (!$contact->is_read) {
            $contact->markAsRead();
        }

        return response()->json([
            'success' => true,
            'data' => $contact
        ]);
    }

    // Delete contact (Admin)
    public function destroy($id)
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contact deleted successfully'
        ]);
    }

    // Bulk delete (Admin)
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:contacts,id'
        ]);

        Contact::whereIn('id', $request->ids)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contacts deleted successfully'
        ]);
    }
}
