<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Actions\ManageWorkTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WorkTaskController extends Controller
{
    public function index(Request $request, ManageWorkTask $tasks): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $request->user()->belongsToOrganization($org), 404);
        $query = DB::table('work_tasks as task')->join('users as assignee', 'assignee.id', '=', 'task.assigned_to')
            ->where('task.organization_id', $org->id)
            ->when(! $tasks->manages($org, $request->user()), fn ($query) => $query->where('task.assigned_to', $request->user()->id))
            ->select('task.*', 'assignee.name as assignee_name')->orderByRaw('task.due_at IS NULL')->orderBy('task.due_at');

        return Inertia::render('tasks/Index', [
            'tasks' => $query->paginate(30),
            'members' => $tasks->manages($org, $request->user()) ? $org->users()->orderBy('name')->get(['users.id', 'users.name']) : [],
            'canManage' => $tasks->manages($org, $request->user()),
        ]);
    }

    public function store(Request $request, ManageWorkTask $tasks): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $request->user()->belongsToOrganization($org), 404);
        $tasks->create($org, $request->user(), $this->validated($request, true));

        return back();
    }

    public function update(Request $request, int $task, ManageWorkTask $tasks): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $request->user()->belongsToOrganization($org), 404);
        $tasks->update($org, $request->user(), $task, $this->validated($request, false));

        return back();
    }

    public function complete(Request $request, int $task, ManageWorkTask $tasks): RedirectResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org && $request->user()->belongsToOrganization($org), 404);
        $tasks->complete($org, $request->user(), $task);

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'assigned_to' => [$required, 'integer'],
            'due_at' => ['nullable', 'date'],
            'related_type' => ['nullable', 'in:lead,listing,reservation,lease,sale,unit,job'],
            'related_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
