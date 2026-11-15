/**
 * WP AI Gatekeeper — Admin Suggestion Review & Comment Thread Controller
 *
 * Handles interactive filtering, comment posting, merging, and rejecting
 * of AI suggestions inside both the Post Editor Meta Box and Global Review Queue.
 */
( function () {
	'use strict';

	const config = window.wpAIGatekeeper || {};

	/**
	 * Perform a REST request to the AI Gatekeeper namespace.
	 *
	 * @param {string} path   Relative endpoint path (e.g. '/suggestions/5/merge').
	 * @param {string} method HTTP verb.
	 * @param {Object} body   JSON payload.
	 * @return {Promise<Object>}
	 */
	async function apiRequest( path, method, body ) {
		const url = ( config.restRoot || '/wp-json/ai-gatekeeper/v1' ).replace( /\/$/, '' ) + path;
		const options = {
			method: method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || '',
			},
			credentials: 'same-origin',
		};

		if ( body && method !== 'GET' ) {
			options.body = JSON.stringify( body );
		}

		const response = await fetch( url, options );
		const data = await response.json();

		if ( ! response.ok ) {
			const errorMsg = ( data && data.message ) ? data.message : 'Request failed';
			throw new Error( errorMsg );
		}

		return data;
	}

	/**
	 * Escape HTML special characters for safe DOM insertion.
	 *
	 * @param {string} str Raw string.
	 * @return {string}
	 */
	function escapeHtml( str ) {
		const div = document.createElement( 'div' );
		div.textContent = str || '';
		return div.innerHTML;
	}

	/**
	 * Append a newly created comment to a suggestion card's thread container.
	 *
	 * @param {HTMLElement} card    Suggestion card element.
	 * @param {Object}      comment Comment payload returned by REST API.
	 */
	function appendCommentToCard( card, comment ) {
		const container = card.querySelector( '.wpai-comments-container' );
		if ( ! container ) {
			return;
		}

		const emptyNotice = container.querySelector( '.wpai-no-comments' );
		if ( emptyNotice ) {
			emptyNotice.remove();
		}

		const authorType = comment.author_type === 'ai' ? 'ai' : 'human';
		const badgeLabel = authorType === 'ai' ? 'AI Agent' : 'Human Editor';

		const commentEl = document.createElement( 'div' );
		commentEl.className = 'wpai-thread-comment wpai-comment-' + authorType;
		commentEl.innerHTML =
			'<div class="wpai-comment-header">' +
				'<span class="wpai-author-badge wpai-author-' + authorType + '">' + escapeHtml( badgeLabel ) + '</span> ' +
				'<strong>' + escapeHtml( comment.author_name ) + '</strong> ' +
				'<span class="wpai-comment-time">' + escapeHtml( comment.created_at ) + '</span>' +
			'</div>' +
			'<div class="wpai-comment-body"><p>' + escapeHtml( comment.comment_text ) + '</p></div>';

		container.appendChild( commentEl );
	}

	/**
	 * Display feedback text inside a suggestion card.
	 *
	 * @param {HTMLElement} card    Suggestion card element.
	 * @param {string}      message Message text.
	 * @param {boolean}     isError Whether this is an error message.
	 */
	function setCardFeedback( card, message, isError ) {
		const feedbackEl = card.querySelector( '.wpai-card-feedback' );
		if ( ! feedbackEl ) {
			return;
		}
		feedbackEl.textContent = message;
		feedbackEl.className = 'wpai-card-feedback ' + ( isError ? 'wpai-feedback-error' : 'wpai-feedback-success' );
	}

	/**
	 * Transition a card's visual state after merge or reject.
	 *
	 * @param {HTMLElement} card       Suggestion card element.
	 * @param {Object}      suggestion Updated suggestion object from API.
	 */
	function updateCardState( card, suggestion ) {
		const status = suggestion.status;
		card.dataset.status = status;
		card.className = 'wpai-suggestion-card wpai-status-' + status;

		const badge = card.querySelector( '.wpai-status-badge' );
		if ( badge ) {
			badge.className = 'wpai-status-badge wpai-badge-' + status;
			if ( status === 'merged' ) {
				badge.textContent = ( config.i18n && config.i18n.mergedBadge ) || 'Merged (Locked)';
			} else if ( status === 'rejected' ) {
				badge.textContent = ( config.i18n && config.i18n.rejectedBadge ) || 'Rejected';
			}
		}

		if ( status === 'merged' || suggestion.is_locked ) {
			const replyBox = card.querySelector( '.wpai-reply-box' );
			if ( replyBox ) {
				const banner = document.createElement( 'div' );
				banner.className = 'wpai-locked-banner';
				banner.innerHTML =
					'<span class="dashicons dashicons-lock"></span> ' +
					escapeHtml(
						( config.i18n && config.i18n.lockedNotice ) ||
						'This suggestion has been merged and is permanently locked.'
					);
				replyBox.replaceWith( banner );
			}
		} else if ( status === 'rejected' ) {
			const decisions = card.querySelector( '.wpai-review-decisions' );
			if ( decisions ) {
				decisions.remove();
			}
		}
	}

	/**
	 * Sync the live Classic Editor or Gutenberg editor content if open on the page.
	 *
	 * @param {HTMLElement} card Suggestion card element.
	 */
	function syncEditorContentOnMerge( card ) {
		const targetField = card.dataset.targetField || 'post_content';
		const originalText = card.dataset.originalText || '';
		const suggestedText = card.dataset.suggestedText || '';

		if ( ! originalText ) {
			return;
		}

		// Sync Gutenberg block editor if active.
		if ( window.wp && window.wp.data && window.wp.data.select && window.wp.data.dispatch ) {
			const editorSelect = window.wp.data.select( 'core/editor' );
			const editorDispatch = window.wp.data.dispatch( 'core/editor' );
			if ( editorSelect && editorDispatch ) {
				if ( targetField === 'post_title' ) {
					const currentTitle = editorSelect.getEditedPostAttribute( 'title' ) || '';
					if ( currentTitle.includes( originalText ) ) {
						editorDispatch.editPost( {
							title: currentTitle.replace( originalText, suggestedText ),
						} );
					}
				} else if ( targetField === 'post_excerpt' ) {
					const currentExcerpt = editorSelect.getEditedPostAttribute( 'excerpt' ) || '';
					if ( currentExcerpt.includes( originalText ) ) {
						editorDispatch.editPost( {
							excerpt: currentExcerpt.replace( originalText, suggestedText ),
						} );
					}
				} else {
					const currentContent = editorSelect.getEditedPostAttribute( 'content' ) || '';
					if ( currentContent.includes( originalText ) ) {
						editorDispatch.editPost( {
							content: currentContent.replace( originalText, suggestedText ),
						} );
					}
				}
			}
		}

		// Sync Classic Editor textarea if present.
		if ( targetField === 'post_content' ) {
			const contentField = document.getElementById( 'content' );
			if ( contentField && contentField.value.includes( originalText ) ) {
				contentField.value = contentField.value.replace( originalText, suggestedText );
			}
		} else if ( targetField === 'post_title' ) {
			const titleField = document.getElementById( 'title' );
			if ( titleField && titleField.value.includes( originalText ) ) {
				titleField.value = titleField.value.replace( originalText, suggestedText );
			}
		}
	}

	/**
	 * Attach delegated event listeners for filter tabs and card actions.
	 */
	function initEvents() {
		document.addEventListener( 'click', async function ( event ) {
			// Filter buttons inside meta box.
			const filterBtn = event.target.closest( '.wpai-filter-btn' );
			if ( filterBtn ) {
				const wrapper = filterBtn.closest( '.wpai-meta-box-wrapper' );
				if ( ! wrapper ) {
					return;
				}
				wrapper.querySelectorAll( '.wpai-filter-btn' ).forEach( function ( btn ) {
					btn.classList.remove( 'is-active' );
				} );
				filterBtn.classList.add( 'is-active' );

				const filter = filterBtn.dataset.filter || 'all';
				wrapper.querySelectorAll( '.wpai-suggestion-card' ).forEach( function ( card ) {
					if ( filter === 'all' || card.dataset.status === filter ) {
						card.style.display = '';
					} else {
						card.style.display = 'none';
					}
				} );
				return;
			}

			// Add Comment button.
			const commentBtn = event.target.closest( '.wpai-btn-comment' );
			if ( commentBtn ) {
				const card = commentBtn.closest( '.wpai-suggestion-card' );
				const textarea = card ? card.querySelector( '.wpai-comment-input' ) : null;
				if ( ! card || ! textarea ) {
					return;
				}

				const text = textarea.value.trim();
				if ( ! text ) {
					return;
				}

				const suggestionId = card.dataset.suggestionId;
				commentBtn.disabled = true;
				setCardFeedback( card, '', false );

				try {
					const responseData = await apiRequest(
						'/suggestions/' + suggestionId + '/comments',
						'POST',
						{ comment: text }
					);
					const createdComment = ( responseData && Array.isArray( responseData.comments ) && responseData.comments.length )
						? responseData.comments[ responseData.comments.length - 1 ]
						: responseData;
					appendCommentToCard( card, createdComment );
					textarea.value = '';
					setCardFeedback( card, 'Comment added.', false );
				} catch ( err ) {
					setCardFeedback( card, err.message, true );
				} finally {
					commentBtn.disabled = false;
				}
				return;
			}

			// Merge Suggestion button.
			const mergeBtn = event.target.closest( '.wpai-btn-merge' );
			if ( mergeBtn ) {
				const card = mergeBtn.closest( '.wpai-suggestion-card' );
				if ( ! card ) {
					return;
				}

				const confirmText = ( config.i18n && config.i18n.confirmMerge ) ||
					'Merge this suggestion into the article? Once merged, it cannot be changed anymore.';
				if ( ! window.confirm( confirmText ) ) {
					return;
				}

				const suggestionId = card.dataset.suggestionId;
				const textarea = card.querySelector( '.wpai-comment-input' );
				const optionalNote = textarea ? textarea.value.trim() : '';

				mergeBtn.disabled = true;
				setCardFeedback( card, '', false );

				try {
					const updated = await apiRequest(
						'/suggestions/' + suggestionId + '/merge',
						'POST',
						{ comment: optionalNote }
					);
					if ( optionalNote && updated.comments && updated.comments.length ) {
						appendCommentToCard( card, updated.comments[ updated.comments.length - 1 ] );
					}
					updateCardState( card, updated );
					syncEditorContentOnMerge( card );
					setCardFeedback( card, 'Suggestion merged and permanently locked.', false );
				} catch ( err ) {
					setCardFeedback( card, err.message, true );
					mergeBtn.disabled = false;
				}
				return;
			}

			// Reject Suggestion button.
			const rejectBtn = event.target.closest( '.wpai-btn-reject' );
			if ( rejectBtn ) {
				const card = rejectBtn.closest( '.wpai-suggestion-card' );
				if ( ! card ) {
					return;
				}

				const confirmText = ( config.i18n && config.i18n.confirmReject ) || 'Reject this suggestion?';
				if ( ! window.confirm( confirmText ) ) {
					return;
				}

				const suggestionId = card.dataset.suggestionId;
				const textarea = card.querySelector( '.wpai-comment-input' );
				const optionalReason = textarea ? textarea.value.trim() : '';

				rejectBtn.disabled = true;
				setCardFeedback( card, '', false );

				try {
					const updated = await apiRequest(
						'/suggestions/' + suggestionId + '/reject',
						'POST',
						{ comment: optionalReason }
					);
					if ( optionalReason && updated.comments && updated.comments.length ) {
						appendCommentToCard( card, updated.comments[ updated.comments.length - 1 ] );
					}
					if ( textarea ) {
						textarea.value = '';
					}
					updateCardState( card, updated );
					setCardFeedback( card, 'Suggestion rejected.', false );
				} catch ( err ) {
					setCardFeedback( card, err.message, true );
					rejectBtn.disabled = false;
				}
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initEvents );
	} else {
		initEvents();
	}
} )();
