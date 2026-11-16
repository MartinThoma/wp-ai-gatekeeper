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
	 * Make the buttons match the reply box. With a comment typed, "Comment" is the
	 * primary action and Merge / Reject become "Comment & Merge" / "Comment &
	 * Reject" (they post the comment too). With an empty box, Merge is primary
	 * and the comment button is disabled.
	 *
	 * @param {HTMLElement} card Suggestion card element.
	 */
	function syncReplyActions( card ) {
		const textarea = card.querySelector( '.wpai-comment-input' );
		if ( ! textarea ) {
			return;
		}

		const i18n = config.i18n || {};
		const hasText = textarea.value.trim() !== '';
		const commentBtn = card.querySelector( '.wpai-btn-comment' );
		const mergeBtn = card.querySelector( '.wpai-btn-merge' );
		const rejectBtn = card.querySelector( '.wpai-btn-reject' );

		if ( commentBtn ) {
			commentBtn.classList.toggle( 'button-primary', hasText );
			commentBtn.disabled = ! hasText;
			commentBtn.textContent = hasText ? ( i18n.comment || 'Comment' ) : ( i18n.addComment || 'Add Comment' );
		}
		if ( mergeBtn ) {
			mergeBtn.classList.toggle( 'button-primary', ! hasText );
			mergeBtn.textContent = hasText ? ( i18n.commentAndMerge || 'Comment & Merge' ) : ( i18n.merge || 'Merge Suggestion' );
		}
		if ( rejectBtn ) {
			rejectBtn.textContent = hasText ? ( i18n.commentAndReject || 'Comment & Reject' ) : ( i18n.reject || 'Reject' );
		}
	}

	/**
	 * Describe the editor open on this page for the given post, if any.
	 *
	 * Merging changes the saved article. An editor holding unsaved changes would
	 * write its older version back on the next save and undo the merge, so the
	 * caller refuses to merge in that case and reloads the editor afterwards.
	 *
	 * @param {string|number} postId Post the suggestion belongs to.
	 * @return {{dirty: boolean}|null} Editor state, or null when no editor for this post is open.
	 */
	function editorForPost( postId ) {
		const id = Number( postId );
		const data = window.wp && window.wp.data;
		const blockEditor = data && data.select && data.select( 'core/editor' );
		if ( blockEditor && typeof blockEditor.getCurrentPostId === 'function' && Number( blockEditor.getCurrentPostId() ) === id ) {
			return { dirty: !! blockEditor.isEditedPostDirty() };
		}

		const classicId = document.getElementById( 'post_ID' );
		if ( classicId && Number( classicId.value ) === id ) {
			const server = window.wp && window.wp.autosave && window.wp.autosave.server;
			return { dirty: !! ( server && typeof server.postChanged === 'function' && server.postChanged() ) };
		}

		return null;
	}

	/**
	 * Show or hide the complete article with the suggestion highlighted in place.
	 * The preview is fetched on first open and kept for later toggles.
	 *
	 * @param {HTMLElement} button Toggle button inside a suggestion card.
	 */
	async function toggleArticlePreview( button ) {
		const card = button.closest( '.wpai-suggestion-card' );
		const panel = card ? card.querySelector( '.wpai-article-preview' ) : null;
		const label = button.querySelector( '.wpai-btn-preview-label' );
		if ( ! panel ) {
			return;
		}

		const i18n = config.i18n || {};
		const opening = panel.hidden;
		panel.hidden = ! opening;
		button.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
		if ( label ) {
			label.textContent = opening ? ( i18n.hideArticle || 'Hide full article' ) : ( i18n.showArticle || 'Show in full article' );
		}
		if ( ! opening || panel.dataset.loaded === '1' ) {
			return;
		}

		panel.setAttribute( 'aria-busy', 'true' );
		panel.textContent = i18n.loadingArticle || 'Loading article…';
		try {
			const data = await apiRequest( '/suggestions/' + card.dataset.suggestionId + '/preview', 'GET' );
			panel.innerHTML = '';
			if ( data.notice ) {
				const notice = document.createElement( 'p' );
				notice.className = 'wpai-preview-notice';
				notice.textContent = data.notice;
				panel.appendChild( notice );
			}
			const article = document.createElement( 'div' );
			article.className = 'wpai-preview-article';
			article.innerHTML = data.html; // Server-side filtered with wp_kses_post.
			panel.appendChild( article );
			panel.dataset.loaded = '1';

			const change = article.querySelector( '.wpai-preview-change' );
			if ( change ) {
				panel.scrollTop = Math.max( 0, change.offsetTop - panel.clientHeight / 3 );
			}
		} catch ( err ) {
			panel.textContent = err.message;
		} finally {
			panel.removeAttribute( 'aria-busy' );
		}
	}

	/**
	 * Attach delegated event listeners for filter tabs and card actions.
	 */
	function initEvents() {
		document.addEventListener( 'input', function ( event ) {
			if ( event.target.classList && event.target.classList.contains( 'wpai-comment-input' ) ) {
				const card = event.target.closest( '.wpai-suggestion-card' );
				if ( card ) {
					syncReplyActions( card );
				}
			}
		} );

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

			// Show / hide the full article.
			const previewBtn = event.target.closest( '.wpai-btn-preview' );
			if ( previewBtn ) {
				toggleArticlePreview( previewBtn );
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
					syncReplyActions( card );
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

				const editor = editorForPost( card.dataset.postId );
				if ( editor && editor.dirty ) {
					setCardFeedback(
						card,
						( config.i18n && config.i18n.saveBeforeMerge ) || 'Save your changes first, then merge.',
						true
					);
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
					if ( editor ) {
						// Show exactly what the server merged; the editor had no unsaved changes.
						setCardFeedback( card, ( config.i18n && config.i18n.mergedReloading ) || 'Merged. Reloading the editor…', false );
						window.location.reload();
					} else {
						setCardFeedback( card, 'Suggestion merged and permanently locked.', false );
					}
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
					syncReplyActions( card );
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
