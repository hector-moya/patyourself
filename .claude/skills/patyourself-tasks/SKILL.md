---
name: patyourself-tasks
description: Pull, work on, and update PatYourSelf tasks from ClickUp. Use when asked to start the next task, work a ticket, or sync build progress for the PatYourSelf habit-coaching app.
---

# PatYourSelf — ClickUp task runner

Build the PatYourSelf habit-coaching app one ClickUp task at a time. Tasks live in
ClickUp; this repo is the codebase.

## ClickUp coordinates
The account has MULTIPLE workspaces, so ALWAYS pass workspace_id or the tools error out.
- workspace_id (Alitor): 9016960004   ← always pass this
- Folder "PatYourSelf": 90169845348
- Phase 1 list (Web App + API / MVP): 901615197371   ← build this first
- Phase 2 list (Mobile App): 901615197372            ← only after Phase 1 is fully done

## Picking the next task
1. List open tasks in the Phase 1 list (901615197371), excluding anything already complete.
2. Tasks are numbered (1, 2, 3...) — that number is the build order. Take the lowest-numbered
   task that isn't complete. The foundation tasks (1–7, marked high priority) come first.
3. Don't touch a Phase 2 task until every Phase 1 task is complete.

## Working a task
1. Read the task name + description in full. Restate the goal and a short plan before coding.
2. Set the task status to "in progress" in ClickUp before starting.
3. Implement it in this repo, respecting the principles below.
4. When done:
   - Set the task status to "complete".
   - Add a ClickUp comment summarizing what was built, files touched, and any deviations.
5. Stop and report back. Don't auto-chain into the next task unless asked.

If unsure of the exact status names, fetch the task/list first and use the matching status
(typically "to do" → "in progress" → "complete").

## Project principles (respect while coding)
- AI authors data, UI renders it. The LLM produces structured Intention objects; components
  only render them. Keep that separation.
- All LLM calls are server-side (Laravel), for security and cost control. Never call the LLM
  from the client.
- CoachService is provider-agnostic — code against the interface, keep the vendor swappable.
- Strategies are versioned — failures record the user-stated reason and shift the intervention
  point up/down the behavioral chain. Never rewrite history in place.
- Pattern detection uses rolling summaries, not ML.

## Prereq
Assumes the ClickUp MCP server is connected (`claude mcp list` shows `clickup`). If task tools
aren't available, run `/mcp` to authenticate. it supposed to help us connect to our clickup where we have the tasks for this project
