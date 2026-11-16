@extends('layouts.app')

{{--
    Archiving settings (ArchiveSettingsController). Panel revision #6: eligibility rules and
    retention periods are real and saved to archive_settings; "Archive eligible now" previews
    exactly who matches the policy before anything happens; restore reuses the existing
    /applicants/{ref}/restore endpoint; permanent deletion is its own action, gated server-side
    to records that have already passed the retention window, and typed "DELETE" to confirm.
--}}

@section('title', 'iARIS — Archiving')
@section('page-title', 'Archiving')
@section('page-subtitle', 'Eligibility rules, retention periods, and permanent deletion for applicant records')

@include('partials.admin-workspace', ['workspacePage' => 'archive-page', 'workspaceGroup' => 'System', 'workspaceLabel' => 'Archiving'])

@section('content')
    @if (session('success'))
        <div class="alert alert-success d-flex align-items-center gap-2 small"><i class="bi bi-check-circle" aria-hidden="true"></i> {{ session('success') }}</div>
    @endif

    <div class="row g-4">
        {{-- ========== POLICY ========== --}}
        <div class="col-12 col-xl-5">
            <form class="card border-0 shadow-sm rounded-4 mb-4" method="POST" action="{{ route('admin.archive') }}">
                @csrf
                @method('PUT')
                <div class="card-body p-4">
                    <h2 class="fs-6 fw-bold mb-0">Eligibility &amp; retention policy</h2>
                    <p class="small text-body-secondary">Applies to every division. Based on when each applicant's school year ended.</p>

                    <label class="form-label small fw-bold text-uppercase text-body-secondary mt-3" for="enrolledYears">Enrolled applicants — archive after</label>
                    <div class="input-group mb-1">
                        <input type="number" class="form-control" id="enrolledYears" name="enrolled_after_years" value="{{ old('enrolled_after_years', $settings->enrolled_after_years) }}" min="1" max="10" required>
                        <span class="input-group-text">year(s)</span>
                    </div>
                    <div class="form-text mb-3">Handed over to the Registrar; kept active longer for reference.</div>

                    <label class="form-label small fw-bold text-uppercase text-body-secondary" for="notEnrolledYears">Did not enroll — archive after</label>
                    <div class="input-group mb-1">
                        <input type="number" class="form-control" id="notEnrolledYears" name="not_enrolled_after_years" value="{{ old('not_enrolled_after_years', $settings->not_enrolled_after_years) }}" min="1" max="10" required>
                        <span class="input-group-text">year(s)</span>
                    </div>
                    <div class="form-text mb-3">Pending, for exam, or never completed — no longer actionable.</div>

                    <label class="form-label small fw-bold text-uppercase text-body-secondary" for="deleteYears">Permanently delete after being archived for</label>
                    <div class="input-group mb-1">
                        <input type="number" class="form-control" id="deleteYears" name="delete_after_years" value="{{ old('delete_after_years', $settings->delete_after_years) }}" min="1" max="10" required>
                        <span class="input-group-text">year(s)</span>
                    </div>
                    <div class="form-text">A separate, later window — archiving never deletes a record by itself.</div>
                </div>
                <div class="card-footer bg-transparent border-top d-flex justify-content-end p-3">
                    <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-floppy me-1" aria-hidden="true"></i> Save policy</button>
                </div>
            </form>
        </div>

        {{-- ========== ELIGIBLE TO ARCHIVE (preview) ========== --}}
        <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h2 class="fs-6 fw-bold mb-0">Eligible for archiving</h2>
                        <span class="badge rounded-pill bg-body-tertiary text-body border">{{ $eligibleTotal }} record(s)</span>
                    </div>
                    <p class="small text-body-secondary">A preview — nothing is archived until you confirm below.</p>

                    @if ($eligible->isEmpty())
                        <div class="rounded-3 bg-body-tertiary border text-center text-body-secondary small py-4">
                            <i class="bi bi-check2-circle fs-4 d-block mb-1" aria-hidden="true"></i>
                            Nothing matches the current policy right now.
                        </div>
                    @else
                        <div class="table-responsive" style="max-height: 320px;">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light"><tr class="small text-uppercase"><th>Reference</th><th>Applicant</th><th>Division</th><th>Why</th></tr></thead>
                                <tbody>
                                    @foreach ($eligible as $row)
                                        <tr>
                                            <td class="font-monospace small">{{ $row['ref'] }}</td>
                                            <td class="small">{{ $row['name'] }}</td>
                                            <td class="small">{{ $row['module'] }}</td>
                                            <td class="small text-body-secondary">{{ $row['reason'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($eligibleTotal > $eligible->count())
                            <div class="small text-body-secondary mt-2">Showing the first {{ $eligible->count() }} of {{ $eligibleTotal }}.</div>
                        @endif
                    @endif
                </div>
                <div class="card-footer bg-transparent border-top d-flex justify-content-end p-3">
                    <form method="POST" action="{{ route('admin.archive.run') }}" onsubmit="return confirm('Archive all {{ $eligibleTotal }} eligible record(s) now? Each will be logged and can still be restored afterward.');">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary fw-semibold" @disabled($eligibleTotal === 0)><i class="bi bi-archive me-1" aria-hidden="true"></i> Archive eligible records now</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== ARCHIVED BATCHES (restore / permanent delete, a whole import at once) ========== --}}
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h2 class="fs-6 fw-bold mb-0">Archived batches</h2>
            <p class="small text-body-secondary">Records archived from the same import, grouped so you can restore or permanently delete all of them in one action instead of one at a time.</p>

            @if ($archivedBatches->isEmpty())
                <div class="rounded-3 bg-body-tertiary border text-center text-body-secondary small py-4">
                    <i class="bi bi-archive fs-4 d-block mb-1" aria-hidden="true"></i>
                    No archived import batches yet.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr class="small text-uppercase"><th>Import file</th><th>Archived records</th><th>Last archived</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            @foreach ($archivedBatches as $row)
                                <tr>
                                    <td class="small">{{ $row['filename'] }}</td>
                                    <td class="small">{{ $row['archivedCount'] }}</td>
                                    <td class="small">{{ $row['lastArchivedAt'] }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <form method="POST" action="{{ url('/admin/archive/batch/' . $row['batchId'] . '/restore') }}" onsubmit="return confirm('Restore all {{ $row['archivedCount'] }} record(s) from {{ $row['filename'] }}?');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Restore all</button>
                                            </form>
                                            @if ($row['deletable'])
                                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal" data-kind="batch" data-id="{{ $row['batchId'] }}" data-name="all {{ $row['archivedCount'] }} record(s) from {{ $row['filename'] }}"><i class="bi bi-trash me-1" aria-hidden="true"></i> Delete all permanently</button>
                                            @else
                                                <span tabindex="0" title="Eligible for permanent deletion on {{ $row['deletableOn'] }}">
                                                    <button type="button" class="btn btn-sm btn-outline-danger" disabled><i class="bi bi-trash me-1" aria-hidden="true"></i> Delete all permanently</button>
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ========== ARCHIVED RECORDS added individually (not via an import) ========== --}}
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <h2 class="fs-6 fw-bold mb-0">Archived records</h2>
            <p class="small text-body-secondary">Records archived one at a time (not part of a bulk import). Restore returns a record to its normal list. Permanent deletion only becomes available once a record has passed the retention window above, and cannot be undone.</p>

            @if ($archivedIndividual->isEmpty())
                <div class="rounded-3 bg-body-tertiary border text-center text-body-secondary small py-4">
                    <i class="bi bi-archive fs-4 d-block mb-1" aria-hidden="true"></i>
                    No individually archived records.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr class="small text-uppercase"><th>Reference</th><th>Applicant</th><th>Division</th><th>Archived</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            @foreach ($archivedIndividual as $row)
                                <tr>
                                    <td class="font-monospace small">{{ $row['ref'] }}</td>
                                    <td class="small">{{ $row['name'] }}</td>
                                    <td class="small">{{ $row['module'] }}</td>
                                    <td class="small">{{ $row['archivedAt'] }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <form method="POST" action="{{ url('/applicants/' . $row['ref'] . '/restore') }}" onsubmit="return confirm('Restore {{ $row['ref'] }}?');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Restore</button>
                                            </form>
                                            @if ($row['deletable'])
                                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal" data-kind="applicant" data-id="{{ $row['ref'] }}" data-name="{{ $row['name'] }}"><i class="bi bi-trash me-1" aria-hidden="true"></i> Delete permanently</button>
                                            @else
                                                <span tabindex="0" title="Eligible for permanent deletion on {{ $row['deletableOn'] }}">
                                                    <button type="button" class="btn btn-sm btn-outline-danger" disabled><i class="bi bi-trash me-1" aria-hidden="true"></i> Delete permanently</button>
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 d-flex justify-content-center">{{ $archivedIndividual->links('pagination::bootstrap-5') }}</div>
            @endif
        </div>
    </div>

    {{-- One confirm modal for permanent deletion, reused for every row and every batch --}}
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content border-0 rounded-4 overflow-hidden" id="deleteForm" method="POST" action="">
                @csrf
                @method('DELETE')
                <div class="modal-header bg-danger text-white border-0 p-4">
                    <h5 class="modal-title fw-bold" id="deleteModalTitle"><i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>Permanently delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p>This permanently deletes <strong id="deleteModalName"></strong>. This cannot be undone and is logged to the Audit Logs.</p>
                    <label for="deleteConfirm" class="form-label small fw-bold text-uppercase text-body-secondary">Type <code>DELETE</code> to confirm</label>
                    <input type="text" class="form-control" id="deleteConfirm" name="confirm" autocomplete="off" required pattern="DELETE">
                </div>
                <div class="modal-footer border-top p-3">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-semibold" id="deleteSubmit" disabled><i class="bi bi-trash me-1" aria-hidden="true"></i> Delete permanently</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        const deleteModalEl = document.getElementById('deleteModal');
        deleteModalEl?.addEventListener('show.bs.modal', e => {
            const button = e.relatedTarget;
            document.getElementById('deleteModalName').textContent = button.dataset.name;
            const base = '{{ url('/admin/archive') }}';
            document.getElementById('deleteForm').action = button.dataset.kind === 'batch'
                ? base + '/batch/' + encodeURIComponent(button.dataset.id)
                : base + '/' + encodeURIComponent(button.dataset.id);
            document.getElementById('deleteConfirm').value = '';
            document.getElementById('deleteSubmit').disabled = true;
        });
        document.getElementById('deleteConfirm')?.addEventListener('input', e => {
            document.getElementById('deleteSubmit').disabled = e.target.value !== 'DELETE';
        });
    </script>
@endsection
