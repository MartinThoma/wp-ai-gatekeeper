/**
 * WP AI Gatekeeper — Gutenberg Block Editor Sidebar Panel
 *
 * Registers a native PluginSidebar inside the WordPress Block Editor so human
 * editors can inspect inline diffs, converse with the AI agent, and merge or
 * reject suggestions without leaving the block canvas.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.components || ! wp.data ) {
		return;
	}

	const { registerPlugin } = wp.plugins;
	const editPostModule = wp.editor || wp.editPost || {};
	const { PluginSidebar, PluginSidebarMoreMenuItem } = editPostModule;
	const { createElement: el, useState, useEffect, Fragment } = wp.element;
	const { PanelBody, Button, TextareaControl, Notice, Spinner, Badge } = wp.components;
	const { useSelect } = wp.data;
	const apiFetch = wp.apiFetch;
	const i18n = ( window.wpAIGatekeeper && window.wpAIGatekeeper.i18n ) || {};

	if ( ! PluginSidebar ) {
		return;
	}

	/**
	 * Render a compact diff view inside the Gutenberg sidebar.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} React element.
	 */
	function WordDiffView( props ) {
		const { suggestion } = props;
		return el(
			'div',
			{
				className: 'wpai-sidebar-diff-wrapper',
				dangerouslySetInnerHTML: { __html: suggestion.diff_html || '' },
			}
		);
	}

	/**
	 * Render a single suggestion card inside the Gutenberg sidebar.
	 *
	 * @param {Object} props Component props.
	 * @return {Object} React element.
	 */
	function SidebarSuggestionCard( props ) {
		const { suggestion, onUpdated, isEditorDirty } = props;
		const [ replyText, setReplyText ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ notice, setNotice ] = useState( '' );

		const isLocked = suggestion.is_locked || suggestion.status === 'merged';

		async function handleComment() {
			if ( ! replyText.trim() ) {
				return;
			}
			setBusy( true );
			setError( '' );
			try {
				await apiFetch( {
					path: '/ai-gatekeeper/v1/suggestions/' + suggestion.id + '/comments',
					method: 'POST',
					data: { comment: replyText },
				} );
				setReplyText( '' );
				onUpdated();
			} catch ( err ) {
				setError( err.message || 'Failed to add comment.' );
			} finally {
				setBusy( false );
			}
		}

		async function handleMerge() {
			// Merging changes the saved article; saving unsaved (older) editor
			// content afterwards would undo it.
			if ( isEditorDirty ) {
				setError( i18n.saveBeforeMerge || 'Save your changes first, then merge.' );
				return;
			}
			if ( ! window.confirm( i18n.confirmMerge || 'Merge this suggestion into the article?' ) ) {
				return;
			}
			setBusy( true );
			setError( '' );
			try {
				await apiFetch( {
					path: '/ai-gatekeeper/v1/suggestions/' + suggestion.id + '/merge',
					method: 'POST',
					data: { comment: replyText },
				} );
				setNotice( i18n.mergedReloading || 'Merged. Reloading the editor…' );
				// Reload so the editor shows exactly what the server merged.
				window.location.reload();
			} catch ( err ) {
				setError( err.message || 'Failed to merge suggestion.' );
				setBusy( false );
			}
		}

		async function handleReject() {
			setBusy( true );
			setError( '' );
			try {
				await apiFetch( {
					path: '/ai-gatekeeper/v1/suggestions/' + suggestion.id + '/reject',
					method: 'POST',
					data: { comment: replyText },
				} );
				setReplyText( '' );
				onUpdated();
			} catch ( err ) {
				setError( err.message || 'Failed to reject suggestion.' );
			} finally {
				setBusy( false );
			}
		}

		return el(
			'div',
			{ className: 'wpai-sidebar-card wpai-status-' + suggestion.status },
			el(
				'div',
				{ className: 'wpai-card-meta' },
				el(
					'span',
					{ className: 'wpai-status-badge wpai-badge-' + suggestion.status },
					suggestion.status === 'merged' ? 'Merged (Locked)' : suggestion.status.toUpperCase()
				),
				el( 'strong', null, ' #' + suggestion.id ),
				el( 'span', { className: 'wpai-field-pill' }, suggestion.target_field )
			),
			el( WordDiffView, { suggestion: suggestion } ),
			el(
				'div',
				{ className: 'wpai-comments-container' },
				( suggestion.comments || [] ).map( function ( c ) {
					return el(
						'div',
						{
							key: c.id,
							className: 'wpai-thread-comment wpai-comment-' + c.author_type,
						},
						el(
							'div',
							{ className: 'wpai-comment-header' },
							el(
								'span',
								{ className: 'wpai-author-badge wpai-author-' + c.author_type },
								c.author_type === 'ai' ? 'AI' : 'Human'
							),
							el( 'strong', null, c.author_name )
						),
						el( 'div', { className: 'wpai-comment-body' }, el( 'p', null, c.comment_text ) )
					);
				} )
			),
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			notice ? el( Notice, { status: 'success', isDismissible: false }, notice ) : null,
			isLocked
				? el(
					'div',
					{ className: 'wpai-locked-banner' },
					'Merged & permanently locked.'
				)
				: el(
					Fragment,
					null,
					el( TextareaControl, {
						label: 'Reply / Feedback for AI',
						value: replyText,
						onChange: setReplyText,
						rows: 2,
					} ),
					el(
						'div',
						{ className: 'wpai-card-actions' },
						el(
							Button,
							{
								variant: 'secondary',
								isSmall: true,
								disabled: busy,
								onClick: handleComment,
							},
							'Comment'
						),
						suggestion.status === 'open'
							? el(
								'div',
								{ className: 'wpai-review-decisions' },
								el(
									Button,
									{
										variant: 'primary',
										isSmall: true,
										disabled: busy,
										onClick: handleMerge,
									},
									'Merge'
								),
								el(
									Button,
									{
										isDestructive: true,
										isSmall: true,
										disabled: busy,
										onClick: handleReject,
									},
									'Reject'
								)
							)
							: null
					)
				)
		);
	}

	/**
	 * Main Gutenberg Sidebar component.
	 *
	 * @return {Object} React element.
	 */
	function AIGatekeeperSidebar() {
		const postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		const isEditorDirty = useSelect( function ( select ) {
			return select( 'core/editor' ).isEditedPostDirty();
		}, [] );

		const [ suggestions, setSuggestions ] = useState( [] );
		const [ loading, setLoading ] = useState( false );

		function loadSuggestions() {
			if ( ! postId ) {
				return;
			}
			setLoading( true );
			apiFetch( {
				path: '/ai-gatekeeper/v1/posts/' + postId + '/suggestions',
			} )
				.then( function ( data ) {
					const list = Array.isArray( data )
						? data
						: ( data && Array.isArray( data.items ) ? data.items : [] );
					setSuggestions( list );
				} )
				.finally( function () {
					setLoading( false );
				} );
		}

		useEffect( function () {
			loadSuggestions();
		}, [ postId ] );

		return el(
			Fragment,
			null,
			el(
				PluginSidebarMoreMenuItem,
				{ target: 'wp-ai-gatekeeper-sidebar', icon: 'shield-alt' },
				'AI Gatekeeper Suggestions'
			),
			el(
				PluginSidebar,
				{
					name: 'wp-ai-gatekeeper-sidebar',
					title: 'AI Gatekeeper (PR Review)',
					icon: 'shield-alt',
				},
				el(
					PanelBody,
					{ title: 'Article Suggestions (' + suggestions.length + ')', initialOpen: true },
					el(
						Button,
						{
							variant: 'tertiary',
							isSmall: true,
							onClick: loadSuggestions,
							style: { marginBottom: '10px' },
						},
						'Refresh Suggestions'
					),
					loading ? el( Spinner, null ) : null,
					! loading && suggestions.length === 0
						? el( 'p', null, 'No AI suggestions for this article yet.' )
						: suggestions.map( function ( item ) {
							return el( SidebarSuggestionCard, {
								key: item.id,
								suggestion: item,
								onUpdated: loadSuggestions,
								isEditorDirty: isEditorDirty,
							} );
						} )
				)
			)
		);
	}

	registerPlugin( 'wp-ai-gatekeeper', {
		render: AIGatekeeperSidebar,
		icon: 'shield-alt',
	} );
} )( window.wp );
