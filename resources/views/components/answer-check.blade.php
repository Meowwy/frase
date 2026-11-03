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
        const off = (a, b) => distance(a, b) === 1 || bare(a) === bare(b);
        const near = (accepted, typed) => accepted.some(a => off(a, typed));

        // Splits a normalized answer into [leading article, rest]; the article is null when it
        // doesn't open with one of `articles` followed by more words.
        const split = (s, articles) => {
            const m = articles.length && s.match(new RegExp('^(' + articles.map(a => a.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')\\s+(.+)$', 'u'));
            return m ? [m[1], m[2]] : [null, s];
        };

        // Like near(), but an accepted answer that opens with an article is judged in two parts:
        // the article (missing or a different one is a small mistake) and the rest (exact or one
        // character/accent off). Either small mistake alone, or both together, is a near miss;
        // a wrong word never is. Call it only after an exact match has been ruled out.
        const nearWithArticles = (accepted, typed, articles) => accepted.some(a => {
            const [article, word] = split(a, articles);
            if (! article) return off(a, typed);
            const rest = split(typed, articles)[1];
            return rest === word || off(word, rest);
        });

        return { normalize, near, nearWithArticles };
    })();
</script>
