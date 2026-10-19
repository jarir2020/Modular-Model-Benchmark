<?php

// Implemented with model: kr/qwen3-coder-next (Qwen 3 Coder Next) — Comments module, roadmap #5.

namespace Modules\Comments\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Comments\Models\Comment;
use Modules\Tasks\Models\Task;

class CommentController extends Controller
{
    public function index(Request $request): View
    {
        $taskId = $this->queryScalar($request, 'task_id');

        if ($taskId === null || $taskId === '') {
            return view('comments::index', [
                'pageProps' => [
                    'initialComments' => [],
                    'taskId' => null,
                    'csrfToken' => csrf_token(),
                    'routes' => [
                        'index' => route('comments.index'),
                        'store' => route('comments.store'),
                        'update' => route('comments.update', ['comment' => '__COMMENT_ID__']),
                        'destroy' => route('comments.destroy', ['comment' => '__COMMENT_ID__']),
                    ],
                ],
            ]);
        }

        $task = Task::query()->findOrFail($taskId);

        $comments = Comment::query()
            ->with(['user:id,name'])
            ->where('task_id', $taskId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $comment): array => $this->commentPayload($comment))
            ->values()
            ->all();

        return view('comments::index', [
            'pageProps' => [
                'initialComments' => $comments,
                'taskId' => $taskId,
                'taskTitle' => $task->title,
                'csrfToken' => csrf_token(),
                'routes' => [
                    'index' => route('comments.index'),
                    'store' => route('comments.store'),
                    'update' => route('comments.update', ['comment' => '__COMMENT_ID__']),
                    'destroy' => route('comments.destroy', ['comment' => '__COMMENT_ID__']),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedComment($request);

        $comment = Comment::query()->create($data);
        $comment->load(['user:id,name']);

        return response()->json([
            'comment' => $this->commentPayload($comment),
        ], 201);
    }

    public function update(Request $request, Comment $comment): JsonResponse
    {
        $data = $this->validatedComment($request);
        $comment->update($data);

        return response()->json([
            'comment' => $this->commentPayload($comment->refresh()->load(['user:id,name'])),
        ]);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        $comment->delete();

        return response()->json(null, 204);
    }

    /**
     * Read a filter as a scalar. Repeated or array query parameters
     * (for example ?task_id[]=a&task_id[]=b) must not reach the where clauses.
     */
    private function queryScalar(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    /**
     * @return array{task_id: int, body: string}
     */
    private function validatedComment(Request $request): array
    {
        return $request->validate([
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ]);
    }

    /**
     * @return array{id: int, task_id: int, user_id: int, user_name: ?string, body: string, created_at: ?string}
     */
    private function commentPayload(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'task_id' => $comment->task_id,
            'user_id' => $comment->user_id,
            'user_name' => $comment->user?->name,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }
}
