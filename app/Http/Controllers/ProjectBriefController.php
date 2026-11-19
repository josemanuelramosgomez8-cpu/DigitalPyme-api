<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectBriefRequest;
use App\Models\Project;
use App\Models\ProjectBrief;

class ProjectBriefController extends Controller
{
    public function show(Project $project)
    {
        return view('projects.brief', [
            'project' => $project,
            'brief' => $project->brief()->first(),
        ]);
    }

    public function store(ProjectBriefRequest $request, Project $project)
    {
        $brief = ProjectBrief::updateOrCreate(
            ['project_id' => $project->id],
            [
                'answers' => $request->validated('answers'),
                'submitted_at' => now(),
                'submitter_ip' => $request->ip(),
            ],
        );

        return view('projects.brief-submitted', compact('project', 'brief'));
    }
}
