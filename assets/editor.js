/**
 * Editor side of the two dynamic blocks. No build step: plain ES5 with the
 * WordPress globals. The related preview asks the plugin's REST route; the
 * search preview is a placeholder and never spends anything.
 */
(function (wp) {
    'use strict';
    if (!wp || !wp.blocks || !wp.element) {
        return;
    }
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var be = wp.blockEditor;
    var c = wp.components;

    function relatedEdit(props) {
        var a = props.attributes;
        var postId = props.context && props.context.postId;
        var state = useState(null);
        var items = state[0];
        var setItems = state[1];
        useEffect(function () {
            if (!postId || !wp.apiFetch) {
                setItems([]);
                return;
            }
            wp.apiFetch({ path: '/post-embeddings/v1/related/' + postId + '?count=' + a.count })
                .then(function (r) { setItems(Array.isArray(r) ? r : []); })
                .catch(function () { setItems([]); });
        }, [postId, a.count]);

        var list;
        if (items === null) {
            list = el(c.Spinner, null);
        } else if (items.length === 0) {
            list = el('p', null, 'No related posts yet. Readers see the fallback: ' + a.fallback + '.');
        } else {
            list = el('ul', null, items.map(function (it) {
                return el('li', { key: it.id }, it.title);
            }));
        }
        return el('div', be.useBlockProps(),
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Settings' },
                    el(c.RangeControl, { label: 'Count', min: 1, max: 12, value: a.count, onChange: function (v) { props.setAttributes({ count: v }); } }),
                    el(c.TextControl, { label: 'Heading', value: a.heading, onChange: function (v) { props.setAttributes({ heading: v }); } }),
                    el(c.ToggleControl, { label: 'Show date', checked: a.showDate, onChange: function (v) { props.setAttributes({ showDate: v }); } }),
                    el(c.ToggleControl, { label: 'Show excerpt', checked: a.showExcerpt, onChange: function (v) { props.setAttributes({ showExcerpt: v }); } }),
                    el(c.ToggleControl, { label: 'Show image', checked: a.showImage, onChange: function (v) { props.setAttributes({ showImage: v }); } }),
                    el(c.SelectControl, {
                        label: 'When there are no results',
                        value: a.fallback,
                        options: [
                            { label: 'Show nothing', value: 'none' },
                            { label: 'Latest posts of the same type', value: 'recent' },
                            { label: 'Latest posts in the same category', value: 'same_term' }
                        ],
                        onChange: function (v) { props.setAttributes({ fallback: v }); }
                    }),
                    el(c.TextControl, { label: 'Minimum score (empty uses the site setting)', value: a.minScore, onChange: function (v) { props.setAttributes({ minScore: v }); } })
                )
            ),
            a.heading ? el('h2', null, a.heading) : null,
            list
        );
    }

    function searchEdit(props) {
        var a = props.attributes;
        return el('div', be.useBlockProps(),
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Settings' },
                    el(c.RangeControl, { label: 'Results', min: 1, max: 20, value: a.count, onChange: function (v) { props.setAttributes({ count: v }); } }),
                    el(c.TextControl, { label: 'Label', value: a.label, onChange: function (v) { props.setAttributes({ label: v }); } }),
                    el(c.TextControl, { label: 'Button text', value: a.buttonText, onChange: function (v) { props.setAttributes({ buttonText: v }); } }),
                    el(c.ToggleControl, { label: 'Show date', checked: a.showDate, onChange: function (v) { props.setAttributes({ showDate: v }); } }),
                    el(c.ToggleControl, { label: 'Show excerpt', checked: a.showExcerpt, onChange: function (v) { props.setAttributes({ showExcerpt: v }); } })
                )
            ),
            el('label', null, a.label),
            el('input', { type: 'search', disabled: true, placeholder: 'Readers type here' }),
            el('button', { type: 'button', disabled: true }, a.buttonText),
            el('p', null, 'Preview only: no search runs in the editor.')
        );
    }

    wp.blocks.registerBlockType('post-embeddings/related', { edit: relatedEdit, save: function () { return null; } });
    wp.blocks.registerBlockType('post-embeddings/search', { edit: searchEdit, save: function () { return null; } });
}(window.wp));
