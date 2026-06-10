/**
 * Scroll and visually emphasize error regions.
 *
 * @module ext.aibatchEditor.errors
 */
'use strict';

const { nextTick } = require( 'vue' );

/**
 * @param {string|HTMLElement|null} target CSS selector or element
 * @param {string} emphasizeClass
 * @param {number} [durationMs=4500]
 */
function scrollToAndEmphasize( target, emphasizeClass, durationMs ) {
	nextTick( () => {
		const el = typeof target === 'string' ? document.querySelector( target ) : target;
		if ( !el ) {
			return;
		}

		el.scrollIntoView( {
			behavior: 'smooth',
			block: 'center',
			inline: 'nearest'
		} );

		if ( emphasizeClass ) {
			el.classList.add( emphasizeClass );
			window.setTimeout( () => {
				el.classList.remove( emphasizeClass );
			}, durationMs || 4500 );
		}
	} );
}

module.exports = {
	scrollToAndEmphasize
};