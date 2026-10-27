{{-- How a typed answer is matched against its accepted forms, shared by Frammenti's tier I and
     gap-fill: window.AnswerCheck.normalize(s) for an exact match, and .near(accepted, typed)
     for a near miss — one character or one accent off. Case, extra spaces and surrounding
     punctuation never count. --}}
<script>
    window.AnswerCheck = (() => {
        const normalize = s => s.toLowerCase().trim().replace(/\s+/g, ' ').replace(/^[\p{P}\s]+|[\p{P}\s]+$/gu, '');
        const bare = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '');
        function distance(a, b) {
            let row = Array.from({ length: b.length + 1 }, (_, i) => i);
            for (let i = 1; i <= a.length; i++) {
                const next = [i];
                for (let j = 1; j <= b.length; j++) {
                    next[j] = Math.min(row[j] + 1, next[j - 1] + 1, row[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
                }
                row = next;
            }
            return row[b.length];
        }
        const near = (accepted, typed) => accepted.some(a => distance(a, typed) === 1 || bare(a) === bare(typed));

        return { normalize, near };
    })();
</script>
