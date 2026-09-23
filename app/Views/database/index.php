<?php add_js('js/database.js'); ?>

<script>
    const DB_API = <?= json_encode(url('/database/api')) ?>;
</script>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h2 mb-1">Database</h1>
        <?php if ($info): ?>
            <p class="text-muted mb-0 small">
                <?= e($info['file']) ?> &middot; <?= e($info['size_human']) ?> &middot;
                SQLite <?= e($info['sqlite_version']) ?>
                <?php if (!$info['writable']): ?>
                    <span class="badge text-bg-warning ms-1">read-only file</span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" type="button" onclick="dbManager.reload()">
            Refresh
        </button>
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#consoleModal">
            Console
        </button>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createTableModal">
            New table
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= e($error) ?>
        <div class="small mt-1">
            Check <code>config/config.php</code> and make sure <code>storage/</code> is writable.
        </div>
    </div>
<?php endif; ?>

<div id="dbAlert" class="alert d-none" role="alert"></div>

<div class="row g-4">

    <!-- Table list -->
    <div class="col-lg-3">
        <div class="card">
            <div class="card-header">Tables</div>
            <div id="tableList" class="list-group list-group-flush">
                <div class="list-group-item text-muted small">Loading&hellip;</div>
            </div>
        </div>
    </div>

    <!-- Selected table -->
    <div class="col-lg-9">
        <div id="tablePanel" class="card d-none">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <span class="fw-semibold" id="panelTableName"></span>
                    <span class="text-muted small ms-2" id="panelTableMeta"></span>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-primary" type="button" onclick="dbManager.focusNewRow()">
                        Add row
                    </button>
                    <button class="btn btn-sm btn-outline-danger" type="button" id="btnDropTable"
                            onclick="dbManager.dropTable()">
                        Drop table
                    </button>
                </div>
            </div>

            <div class="card-body">
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabRows" type="button">
                            Rows
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabStructure" type="button">
                            Structure
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabRows">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <input type="search" id="rowSearch" class="form-control form-control-sm w-auto"
                                   placeholder="Search rows">
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small" id="rowRange"></span>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-secondary" type="button"
                                            onclick="dbManager.page(-1)">Previous</button>
                                    <button class="btn btn-outline-secondary" type="button"
                                            onclick="dbManager.page(1)">Next</button>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead><tr id="rowsHead"></tr></thead>
                                <tbody id="rowsBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabStructure">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Column</th>
                                        <th>Type</th>
                                        <th>Null</th>
                                        <th>Default</th>
                                        <th>Key</th>
                                    </tr>
                                </thead>
                                <tbody id="structureBody"></tbody>
                            </table>
                        </div>

                        <div class="border rounded p-3 mb-3">
                            <div class="row g-2 align-items-end">
                                <div class="col-sm-3">
                                    <label class="form-label small mb-1" for="newColumnName">New column</label>
                                    <input class="form-control form-control-sm" id="newColumnName" placeholder="name">
                                </div>
                                <div class="col-sm-2">
                                    <label class="form-label small mb-1" for="newColumnType">Type</label>
                                    <select class="form-select form-select-sm" id="newColumnType"></select>
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label small mb-1" for="newColumnDefault">Default</label>
                                    <input class="form-control form-control-sm" id="newColumnDefault" placeholder="—">
                                </div>
                                <div class="col-sm-2">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="checkbox" id="newColumnNotNull">
                                        <label class="form-check-label small" for="newColumnNotNull">Required</label>
                                    </div>
                                </div>
                                <div class="col-sm-2 text-sm-end">
                                    <button class="btn btn-sm btn-primary" type="button"
                                            onclick="dbManager.addColumn()">Add column</button>
                                </div>
                            </div>
                            <div class="form-text">
                                SQLite appends new columns to the end of the table. A required column needs a
                                default value, and foreign keys can only be set when the table is created.
                            </div>
                        </div>

                        <div id="structureRelations" class="small text-muted"></div>

                        <pre class="bg-body-secondary p-3 rounded small mt-3 mb-0"><code id="structureSql"></code></pre>
                    </div>
                </div>
            </div>
        </div>

        <div id="tableEmptyState" class="alert alert-secondary">
            Pick a table on the left, or create one.
        </div>
    </div>
</div>

<!-- SQL console -->
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-1">
        <span>SQL</span>
        <small class="text-muted">Statements run together in one transaction and roll back on error</small>
    </div>
    <div class="card-body">
        <textarea id="sqlInput" class="form-control font-monospace mb-2" rows="6"
                  placeholder="SELECT * FROM users LIMIT 10;"></textarea>
        <button class="btn btn-primary" type="button" onclick="dbManager.runSql()">Run</button>
        <div id="sqlResults" class="mt-3"></div>
    </div>
</div>

<!-- New table -->
<div class="modal fade" id="createTableModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5">New table</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="newTableName">Table name</label>
                        <input type="text" class="form-control" id="newTableName" placeholder="products">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width:18%">Column</th>
                                <th style="width:12%">Type</th>
                                <th style="width:8%">Length</th>
                                <th style="width:6%" class="text-center">Key</th>
                                <th style="width:8%" class="text-center">Auto</th>
                                <th style="width:8%" class="text-center">Required</th>
                                <th style="width:8%" class="text-center">Unique</th>
                                <th style="width:12%">Default</th>
                                <th style="width:14%">References</th>
                                <th style="width:4%"></th>
                            </tr>
                        </thead>
                        <tbody id="newTableColumns"></tbody>
                    </table>
                </div>

                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="dbManager.addColumnRow()">
                    Add column
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="dbManager.createTable()">Create table</button>
            </div>
        </div>
    </div>
</div>


<!-- kite.php console -->
<div class="modal fade" id="consoleModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h2 class="modal-title h6 mb-0 font-monospace">kite.php console</h2>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="consoleOutput" class="font-monospace small p-3"
                     style="min-height:320px; white-space:pre-wrap; word-break:break-word;"></div>
            </div>
            <div class="modal-footer border-secondary p-2">
                <div class="input-group">
                    <span class="input-group-text bg-dark text-light border-secondary font-monospace">Kite</span>
                    <input type="text" id="consoleInput"
                           class="form-control bg-dark text-light border-secondary font-monospace"
                           placeholder="make:controller Products" autocomplete="off" spellcheck="false">
                </div>
            </div>
        </div>
    </div>
</div>
