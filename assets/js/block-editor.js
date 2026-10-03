/**
 * Block editor registration for shootcal/testimonials.
 *
 * Plain ES5 with no build step. There is no package.json, no bundler and no JSX: every
 * element is created with wp.element.createElement and the block is registered with
 * wp.blocks.registerBlockType, against the globals the wp-blocks, wp-element,
 * wp-components, wp-block-editor, wp-data, wp-server-side-render and wp-i18n
 * handles provide. The settings panels live in InspectorControls, so they render
 * in the inspector sidebar and never inside the content canvas.
 *
 * The block is dynamic. save() returns null, so no markup is stored in the post and the
 * void comment form <!-- wp:shootcal/testimonials /--> is valid on its own. Every preview
 * below is the real PHP output, fetched through wp.serverSideRender, which means what an
 * editor sees is exactly what a visitor gets.
 *
 * The category control is the point of this block. It lists the real sct_category terms
 * from the core data store and stores the selection as one comma-separated slug string in
 * the category attribute, which is the same shape the shortcode has always accepted.
 *
 * No request is made from the front end by anything in this file. The term fetch happens
 * in the editor, for a signed-in editor, through the block editor's own REST layer.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var createElement     = wp.element.createElement;
	var useSelect         = wp.data.useSelect;
	var __                = wp.i18n.__;
	var _n                = wp.i18n._n;
	var sprintf           = wp.i18n.sprintf;

	// WordPress normalizes the package default export onto the global, but older builds
	// left it nested, so resolve both shapes.
	var serverSideRender  = wp.serverSideRender || {};
	var ServerSideRender  = serverSideRender.default || serverSideRender;

	var Button          = wp.components.Button;
	var CheckboxControl = wp.components.CheckboxControl;
	var PanelBody       = wp.components.PanelBody;
	var Placeholder     = wp.components.Placeholder;
	var RangeControl    = wp.components.RangeControl;
	var SelectControl   = wp.components.SelectControl;
	var Spinner         = wp.components.Spinner;
	var TextControl     = wp.components.TextControl;
	var ToggleControl   = wp.components.ToggleControl;

	var InspectorControls = wp.blockEditor.InspectorControls;
	var Fragment          = wp.element.Fragment;

	var BLOCK_NAME = 'shootcal/testimonials';
	var TAXONOMY   = 'sct_category';

	var config       = window.sctBlockEditor || {};
	var siteDefaults = config.defaults || {};

	var CEILING     = config.ceiling || 60;
	var CLAMP_MIN   = ( config.clamp && config.clamp.min ) || 2;
	var CLAMP_MAX   = ( config.clamp && config.clamp.max ) || 12;
	var DEF_COUNT   = siteDefaults.count || 21;
	var DEF_TOTAL   = siteDefaults.total || 60;
	var DEF_COLUMNS = siteDefaults.columns || 3;
	var DEF_MORE    = siteDefaults.more || 'hide';
	var DEF_LINES   = siteDefaults.lines || 5;

	/*
	 * One stable query object. core-data keys its resolver cache on the arguments, so a
	 * fresh literal on every render would look like a different query each time and the
	 * terms would be fetched over and over.
	 */
	var TERMS_QUERY = { per_page: -1, hide_empty: false };

	/**
	 * Split the stored comma-separated slug string into a list.
	 *
	 * @param {string} category Block attribute.
	 * @return {string[]} Slugs, de-duplicated, in stored order.
	 */
	function selectedSlugs( category ) {
		var out = [];

		if ( ! category ) {
			return out;
		}

		var parts = String( category ).split( ',' );

		for ( var i = 0; i < parts.length; i++ ) {
			var slug = parts[ i ].trim();

			if ( slug && out.indexOf( slug ) === -1 ) {
				out.push( slug );
			}
		}

		return out;
	}

	/**
	 * Add or remove one slug and return the string to store.
	 *
	 * The result is ordered by the taxonomy so the saved attribute is stable and readable,
	 * and any slug that is not in the current term list is kept rather than dropped, so a
	 * category this editor cannot see is not silently removed from the block.
	 *
	 * @param {Object[]} terms     Terms from the core data store.
	 * @param {string}   current   Stored category attribute.
	 * @param {string}   slug      Slug being toggled.
	 * @param {boolean}  checked   Whether it is being added.
	 * @return {string} Comma-separated slug string.
	 */
	function toggleSlug( terms, current, slug, checked ) {
		var next = selectedSlugs( current );
		var at   = next.indexOf( slug );

		if ( checked && at === -1 ) {
			next.push( slug );
		}

		if ( ! checked && at > -1 ) {
			next.splice( at, 1 );
		}

		var ordered = [];
		var i;

		for ( i = 0; i < terms.length; i++ ) {
			if ( next.indexOf( terms[ i ].slug ) > -1 ) {
				ordered.push( terms[ i ].slug );
			}
		}

		for ( i = 0; i < next.length; i++ ) {
			if ( ordered.indexOf( next[ i ] ) === -1 ) {
				ordered.push( next[ i ] );
			}
		}

		return ordered.join( ',' );
	}

	/**
	 * Read the real sct_category terms and whether they are still loading.
	 *
	 * per_page of -1 is core-data's "give me everything" signal: it pages through the
	 * collection in requests of 100 itself rather than sending -1 to the REST API, which
	 * would fail the per_page bounds check.
	 *
	 * @return {{terms: ?Object[], loading: boolean}}
	 */
	function useTerms() {
		return useSelect(
			function ( select ) {
				var core = select( 'core' );

				return {
					terms: core.getEntityRecords( 'taxonomy', TAXONOMY, TERMS_QUERY ),
					loading: ! core.hasFinishedResolution( 'getEntityRecords', [ 'taxonomy', TAXONOMY, TERMS_QUERY ] )
				};
			},
			[]
		);
	}

	/**
	 * The category filter control.
	 *
	 * @param {Object}   props               Component props.
	 * @param {Object}   props.attributes    Block attributes.
	 * @param {Function} props.setAttributes Attribute setter.
	 * @return {Object} Element.
	 */
	function CategoryFilter( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var selection     = useTerms();
		var terms         = selection.terms;
		var selected      = selectedSlugs( attributes.category );

		if ( selection.loading ) {
			return createElement(
				'p',
				{ className: 'sct-block-editor__hint' },
				createElement( Spinner ),
				' ',
				__( 'Loading testimonial categories...', 'shootcal-testimonials' )
			);
		}

		if ( ! terms || ! terms.length ) {
			return createElement(
				'p',
				{ className: 'sct-block-editor__hint' },
				__( 'No testimonial categories to list yet.', 'shootcal-testimonials' ),
				' ',
				__( 'Add one under Testimonials, Categories, then select this block again.', 'shootcal-testimonials' )
			);
		}

		var checkboxes = [];

		for ( var i = 0; i < terms.length; i++ ) {
			( function ( term ) {
				checkboxes.push(
					createElement( CheckboxControl, {
						key: term.id,
						label: term.name,
						checked: selected.indexOf( term.slug ) > -1,
						__nextHasNoMarginBottom: true,
						onChange: function ( checked ) {
							setAttributes( { category: toggleSlug( terms, attributes.category, term.slug, checked ) } );
						}
					} )
				);
			}( terms[ i ] ) );
		}

		var children = [
			createElement( 'legend', { key: 'legend' }, __( 'Categories', 'shootcal-testimonials' ) )
		].concat( checkboxes );

		children.push(
			createElement(
				'p',
				{ key: 'help', className: 'sct-block-editor__help' },
				selected.length
					? sprintf(
						/* translators: %d: number of selected categories. */
						_n( 'Filtering to %d category.', 'Filtering to %d categories.', selected.length, 'shootcal-testimonials' ),
						selected.length
					)
					: __( 'Tick one or more categories to filter the list. Tick none to show every published testimonial.', 'shootcal-testimonials' )
			)
		);

		if ( selected.length ) {
			children.push(
				createElement(
					Button,
					{
						key: 'clear',
						variant: 'link',
						className: 'sct-block-editor__clear',
						onClick: function () {
							setAttributes( { category: '' } );
						}
					},
					__( 'Clear all categories', 'shootcal-testimonials' )
				)
			);
		}

		return createElement( 'fieldset', { className: 'sct-block-editor__categories' }, children );
	}

	/**
	 * Shown when the PHP render comes back empty.
	 *
	 * @param {Object} props Placeholder props.
	 * @return {Object} Element.
	 */
	function EmptyPreview( props ) {
		return createElement(
			Placeholder,
			{ className: props.className },
			createElement(
				'p',
				null,
				__( 'No published testimonials match these settings yet.', 'shootcal-testimonials' ),
				' ',
				__( 'Publish one under Testimonials, or clear the category filter above.', 'shootcal-testimonials' )
			)
		);
	}

	/**
	 * Block edit component.
	 *
	 * @param {Object}   props               Block props.
	 * @param {Object}   props.attributes    Block attributes.
	 * @param {Function} props.setAttributes Attribute setter.
	 * @return {Object} Element.
	 */
	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var count   = 'number' === typeof attributes.count ? attributes.count : DEF_COUNT;
		var total   = 'number' === typeof attributes.total ? attributes.total : DEF_TOTAL;
		var columns = 'number' === typeof attributes.columns ? attributes.columns : DEF_COLUMNS;
		var lines   = 'number' === typeof attributes.lines ? attributes.lines : DEF_LINES;
		var more    = attributes.more || DEF_MORE;

		return createElement(
			Fragment,
			null,
			createElement(
				InspectorControls,
				null,
				createElement(
					PanelBody,
					{ title: __( 'Category filter', 'shootcal-testimonials' ), initialOpen: true },
					createElement( CategoryFilter, { attributes: attributes, setAttributes: setAttributes } ),
					createElement( ToggleControl, { label: __( 'Show a visitor category selector', 'shootcal-testimonials' ), checked: attributes.filter === 'show', __nextHasNoMarginBottom: true, onChange: function ( value ) { setAttributes( { filter: value ? 'show' : 'hide' } ); } } ),
					createElement( ToggleControl, {
						label: __( 'Allow a URL parameter to override these categories', 'shootcal-testimonials' ),
						help: __( 'Lets ?sct_category=slug replace the ticked categories, so one page can serve two filtered URLs. The page must not be cached on its path alone.', 'shootcal-testimonials' ),
						checked: !! attributes.allowQuery,
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { allowQuery: !! value } );
						}
					} )
				),
				createElement(
					PanelBody,
					{ title: __( 'List', 'shootcal-testimonials' ), initialOpen: false },
					createElement( RangeControl, {
						label: __( 'Testimonials shown', 'shootcal-testimonials' ),
						value: count,
						min: 1,
						max: CEILING,
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { count: value } );
						}
					} ),
					createElement( SelectControl, {
						label: __( 'Columns', 'shootcal-testimonials' ),
						help: __( 'Cards narrow to two columns on tablets and one on phones regardless of this setting.', 'shootcal-testimonials' ),
						value: String( columns ),
						options: [
							{ label: __( 'One', 'shootcal-testimonials' ), value: '1' },
							{ label: __( 'Two', 'shootcal-testimonials' ), value: '2' },
							{ label: __( 'Three', 'shootcal-testimonials' ), value: '3' }
						],
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { columns: parseInt( value, 10 ) } );
						}
					} ),
					createElement( SelectControl, {
						label: __( 'View more', 'shootcal-testimonials' ),
						value: more,
						options: [
							{ label: __( 'Show the list only', 'shootcal-testimonials' ), value: 'hide' },
							{ label: __( 'Show a View more button', 'shootcal-testimonials' ), value: 'show' }
						],
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { more: value } );
						}
					} ),
					'show' === more
						? createElement( RangeControl, {
							label: __( 'Total reviews available', 'shootcal-testimonials' ),
							help: __( 'Shows at least 9 more reviews per click, or the remaining reviews when fewer are left.', 'shootcal-testimonials' ),
							value: total,
							min: count + 1,
							max: CEILING,
							__nextHasNoMarginBottom: true,
							onChange: function ( value ) {
								setAttributes( { total: value } );
							}
						} )
						: null,
					createElement( SelectControl, {
						label: __( 'Order by', 'shootcal-testimonials' ),
						value: attributes.orderby || 'date',
						options: [
							{ label: __( 'Review date', 'shootcal-testimonials' ), value: 'date' },
							{ label: __( 'Star rating', 'shootcal-testimonials' ), value: 'rating' }
						],
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { orderby: value } );
						}
					} ),
					createElement( SelectControl, {
						label: __( 'Direction', 'shootcal-testimonials' ),
						value: attributes.order || 'DESC',
						options: [
							{ label: __( 'Newest or highest first', 'shootcal-testimonials' ), value: 'DESC' },
							{ label: __( 'Oldest or lowest first', 'shootcal-testimonials' ), value: 'ASC' }
						],
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { order: value } );
						}
					} ),
					createElement( RangeControl, {
						label: __( 'Quote lines before clamping', 'shootcal-testimonials' ),
						help: __( 'Without JavaScript the quote is never clamped, so the full text is on the page either way.', 'shootcal-testimonials' ),
						value: lines,
						min: CLAMP_MIN,
						max: CLAMP_MAX,
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { lines: value } );
						}
					} )
				),
				createElement(
					PanelBody,
					{ title: __( 'Heading', 'shootcal-testimonials' ), initialOpen: false },
					createElement( TextControl, {
						label: __( 'Eyebrow', 'shootcal-testimonials' ),
						help: __( 'Small uppercase line above the heading. The heading must be set for any of these to appear.', 'shootcal-testimonials' ),
						value: attributes.eyebrow || '',
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { eyebrow: value } );
						}
					} ),
					createElement( TextControl, {
						label: __( 'Heading', 'shootcal-testimonials' ),
						value: attributes.heading || '',
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { heading: value } );
						}
					} ),
					createElement( TextControl, {
						label: __( 'Intro', 'shootcal-testimonials' ),
						value: attributes.intro || '',
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAttributes( { intro: value } );
						}
					} )
				)
			),
			createElement( ServerSideRender, {
				block: BLOCK_NAME,
				attributes: attributes,
				EmptyResponsePlaceholder: EmptyPreview
			} )
		);
	}

	registerBlockType( BLOCK_NAME, {
		edit: Edit,

		/**
		 * Dynamic block. Nothing is stored, so the void comment form is valid and the
		 * rendered output always comes from render.php.
		 */
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
