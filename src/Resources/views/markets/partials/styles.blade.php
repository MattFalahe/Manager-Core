<style>
    .mk-table { width: 100%; }
    .mk-table th { background: #2a2f3a; color: #c2c7d0 !important; font-weight: 600; padding: 0.6rem 0.8rem; white-space: nowrap; }
    .mk-table td { padding: 0.6rem 0.8rem; vertical-align: middle; border-top: 1px solid #2a3038; }
    .mk-key   { font-family: monospace; color: #dfe3eb !important; font-size: 0.82rem; }
    .mk-meta  { color: #9aa3b3 !important; font-size: 0.82rem; }
    .mk-status-ok  { color: #5acf85 !important; }
    .mk-status-err { color: #e36b6b !important; }
    .mk-actions { white-space: nowrap; }
    .mk-actions form { display: inline-block; margin: 0; }
    .mk-actions .btn { margin-right: 0.25rem; }

    /* A disabled market keeps its name and its toggle at full strength, so
       it still reads as a market you can switch back on. */
    .mk-off .mk-dim { opacity: 0.55; }

    .mk-chip {
        display: inline-flex; align-items: center; gap: 0.25rem;
        font-size: 0.74rem; line-height: 1.4; padding: 0.05rem 0.5rem; margin: 0.1rem 0.15rem 0.1rem 0;
        border: 1px solid; border-radius: 999px; white-space: nowrap; cursor: default;
    }
    .mk-chip-own     { background: #667eea; border-color: #667eea; color: #fff !important; }
    .mk-chip-own-off { background: rgba(220, 53, 69, 0.15); border-color: #dc3545; color: #f39aa3 !important; }
    .mk-chip-ok      { border-color: #28a745; color: #7ddc93 !important; }
    .mk-chip-setup   { border-color: #ffc107; color: #ffd75e !important; }
    .mk-chip-no      { border-color: #3a3f48; border-style: dashed; color: #7b828e !important; }
    .mk-chip-count   { background: rgba(255, 255, 255, 0.12); border-radius: 999px; padding: 0 0.35rem; font-size: 0.68rem; }

    .mk-legend { font-size: 0.78rem; color: #9aa3b3 !important; }

    .mk-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; }
    .mk-tile { border: 1px solid #2c3138; border-radius: 6px; padding: 0.6rem 0.75rem; background: #20232b; }
    .mk-tile-name { color: #fff !important; font-weight: 600; }
    .mk-tile-covers { color: #9aa3b3 !important; font-size: 0.8rem; margin: 0.15rem 0 0.35rem; }

    .mk-group td {
        background: #20232b; border-top: 1px solid #2c3138; border-left: 3px solid #667eea;
        padding: 0.55rem 0.8rem;
    }
    .mk-group-name { color: #fff !important; font-weight: 600; margin-right: 0.4rem; }
    .mk-empty td { color: #9aa3b3 !important; font-size: 0.85rem; padding: 0.7rem 0.8rem 0.9rem; }
</style>
