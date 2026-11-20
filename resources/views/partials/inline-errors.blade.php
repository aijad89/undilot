{{--
    On submit, every field the browser would refuse gets a message under it,
    all at once, instead of one easy-to-miss bubble at a time. A message goes
    when its field is fixed. Forms opt in with `data-inline-errors`.

    Takes $class: the error class of the page's design system
    (ap-field-error or mac-field-error).
--}}
<script>
(function () {
    var cls = @json($class);

    function say(field) {
        var v = field.validity;
        if (v.valueMissing) {
            if (field.type === 'radio' || field.tagName === 'SELECT') return 'Choose one.';
            if (field.type === 'file') return 'Upload a file.';
            if (field.type === 'checkbox') return 'Tick this box.';
            return 'Fill in this field.';
        }
        return field.validationMessage;
    }

    // Radios share one message, under their group; anything else goes at the
    // foot of its field's block, so a button beside the input keeps its place.
    function place(field, p) {
        if (field.type === 'radio') {
            (field.closest('.pf-choices, [data-choices]') || field.parentNode).insertAdjacentElement('afterend', p);
            return;
        }
        var block = field.closest('.ap-field, [class*="col-"]');
        block ? block.appendChild(p) : field.insertAdjacentElement('afterend', p);
    }

    function key(field) { return field.name || field.id; }

    function clear(field) {
        var p = field.form && field.form.querySelector('[data-client-error="' + key(field) + '"]');
        if (p) p.remove();
    }

    document.querySelectorAll('form[data-inline-errors]').forEach(function (form) {
        form.noValidate = true;

        form.addEventListener('submit', function (e) {
            var bad = Array.prototype.filter.call(form.elements, function (f) {
                return f.willValidate && ! f.checkValidity();
            });
            form.querySelectorAll('[data-client-error]').forEach(function (p) { p.remove(); });
            if (! bad.length) return;

            e.preventDefault();
            var seen = {};
            bad.forEach(function (field) {
                if (seen[key(field)]) return;
                seen[key(field)] = true;

                var p = document.createElement('p');
                p.className = cls;
                p.setAttribute('data-client-error', key(field));
                p.setAttribute('role', 'alert');
                p.textContent = say(field);
                place(field, p);
            });
            bad[0].focus();
        });

        form.addEventListener('input', function (e) { if (e.target.checkValidity && e.target.checkValidity()) clear(e.target); });
        form.addEventListener('change', function (e) { if (e.target.checkValidity && e.target.checkValidity()) clear(e.target); });
    });
})();
</script>
