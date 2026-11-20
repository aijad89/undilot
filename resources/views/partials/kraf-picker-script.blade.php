{{-- The craft picker's script: sub-categories filtered by the chosen
     category, then tick boxes for their classifications and products. Shared
     by the admin company page and the participant's profile; both name their
     fields #bidang_id, #sub_bidang_id, #kraf-pengelasan and #kraf-produk.
     Takes $picker = App\Support\KrafPicker::data($company). --}}
<script>
/*
 * The sub-category list, filtered by the chosen category, from data already on
 * the page. No fetch: the whole hierarchy is two short lists, and a round trip
 * per keystroke would be slower than shipping them.
 */
document.addEventListener('DOMContentLoaded', function () {
    const subsByBidang = @json($picker['subBidangs']->map->pluck('name', 'id'));
    const category = document.getElementById('bidang_id');
    const sub = document.getElementById('sub_bidang_id');

    if (! category || ! sub) return;

    category.addEventListener('change', function () {
        const picked = category.selectedOptions[0];
        const subs = subsByBidang[category.value] || {};

        // HASIL LOGAM is stored one row per sub-category, so it is the one that
        // takes several at once. The controller applies the same rule.
        sub.multiple = picked.dataset.name === category.dataset.multi;
        sub.size = sub.multiple ? 6 : 0;

        sub.innerHTML = '';

        if (! sub.multiple) {
            sub.add(new Option('— Pick a sub-category —', ''));
        }

        Object.entries(subs).forEach(([id, name]) => sub.add(new Option(name, id)));
        drawTicks();
    });

    /*
     * Tick boxes for the classifications and the products of every sub-category
     * picked, grouped under its name when there is more than one. The controller
     * takes only the ones under each sub-category; both lists are optional.
     */
    const groups = [
        { box: document.getElementById('kraf-pengelasan'), bySub: @json($picker['pengelasanBySub']), name: 'pengelasan_id[]', prefix: 'pengelasan-' },
        { box: document.getElementById('kraf-produk'), bySub: @json($picker['produksBySub']), name: 'produk_id[]', prefix: 'produk-' },
    ];

    function drawTicks() {
        groups.forEach(drawGroup);
    }

    function drawGroup({ box, bySub, name: fieldName, prefix }) {
        const picked = [...sub.selectedOptions].filter(o => o.value);
        const list = box.querySelector('.kraf-ticks');
        list.innerHTML = '';

        picked.forEach(option => {
            const items = bySub[option.value] || {};
            if (! Object.keys(items).length) return;

            const group = document.createElement('div');
            group.className = 'mb-2';
            if (picked.length > 1) {
                const title = document.createElement('div');
                title.className = 'small fw-semibold';
                title.textContent = option.text;
                group.append(title);
            }
            Object.entries(items).forEach(([id, name]) => {
                const row = document.createElement('div');
                row.className = 'form-check';
                const input = Object.assign(document.createElement('input'), {
                    type: 'checkbox', className: 'form-check-input', name: fieldName,
                    value: id, id: prefix + id,
                });
                const label = Object.assign(document.createElement('label'), {
                    className: 'form-check-label small', htmlFor: input.id, textContent: name,
                });
                row.append(input, label);
                group.append(row);
            });
            list.append(group);
        });

        box.hidden = ! list.children.length;
    }

    sub.addEventListener('change', drawTicks);
});
</script>
