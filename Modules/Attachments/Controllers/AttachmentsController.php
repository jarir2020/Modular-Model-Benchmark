<?php

namespace App\Modules\Attachments\Controllers;

use App\Modules\Attachments\Models\Attachment;
use Illuminate\Http\Request;

class AttachmentsController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate(
            ['file' => 'required|file|max:2048']
        );

        $attachment = new Attachment();
        $attachment->task_id = $request->task_id;
        $attachment->file_name = $validated['file']->getClientOriginalName();
        $attachment->file_path = $validated['file']->store('attachments');
        $attachment->file_size = $validated['file']->getSize();
        $attachment->save();

        return response()->json($attachment, 201);
    }

    public function destroy($id)
    {
        $attachment = Attachment::findOrFail($id);
        $attachment->delete();
        return response()->json(null, 204);
    }
}
?>