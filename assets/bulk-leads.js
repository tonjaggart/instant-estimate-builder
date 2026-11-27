(function () {
    'use strict';
    var form = document.getElementById('ieb-bulk-leads');
    if (!form) return;
    var all = document.getElementById('ieb-select-all-leads');
    var items = Array.prototype.slice.call(form.querySelectorAll('input[name="lead_ids[]"]'));
    var button = form.querySelector('button[type="submit"]');
    var count = form.querySelector('.ieb-bulk-count');
    function sync() {
        var selected = items.filter(function (item) { return item.checked; }).length;
        button.disabled = selected === 0;
        all.checked = selected > 0 && selected === items.length;
        all.indeterminate = selected > 0 && selected < items.length;
        count.textContent = selected ? selected + ' selected on this page.' : 'Select leads on this page.';
    }
    all.addEventListener('change', function () {
        items.forEach(function (item) { item.checked = all.checked; });
        sync();
    });
    items.forEach(function (item) { item.addEventListener('change', sync); });
    form.addEventListener('submit', function (event) {
        var selected = items.filter(function (item) { return item.checked; }).length;
        if (!selected || !window.confirm('Move ' + selected + ' selected lead(s) to Trash? They can be restored until WordPress empties the Trash. Previously sent notifications cannot be recalled.')) {
            event.preventDefault();
        }
    });
    sync();
}());
