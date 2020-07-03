/*!
 * The checkbox hack works without JavaScript for graphical user-interface users, but relies on
 * enhancements to work well for screen reader users. This module provides required a11y
 * interactivity for updating the `aria-expanded` accessibility state, and optional enhancements
 * for avoiding the distracting focus ring when using a pointing device, and target dismissal on
 * focus loss or external click.
 *
 * The checkbox hack is a prevalent pattern in MediaWiki similar to disclosure widgets[0]. Although
 * dated and out-of-fashion, it's surprisingly flexible allowing for both `details` / `summary`-like
 * patterns and more complex (to be used sparingly), less component-like structures where the toggle
 * button and target are in different parts of the Document without an enclosing element, so long as
 * they can be described as a sibling to the input. It's complicated and frequent enough to warrant
 * single implementation.
 *
 * In time, proper disclosure widgets should replace checkbox hacks. However, the second pattern has
 * no equivalent so the checkbox hack may have a continued use case for some time to come.
 *
 * When the abstraction is leaky, the underlying implementation is simpler than anything built to
 * hide it. Attempts to abstract the functionality for the second pattern failed so all related code
 * celebrates the implementation as directly as possible.
 *
 * All the code assumes that when the input is checked, the target is in an expanded state.
 *
 * Consider the disclosure widget pattern first mentioned:
 *
 * ```html
 * <details>                                              <!-- Container -->
 *     <summary>Click to expand navigation menu</summary> <!-- Button -->
 *     <ul>                                               <!-- Target -->
 *         <li>Main page</li>
 *         <li>Random article</li>
 *         <li>Donate to Wikipedia</li>
 *     </ul>
 * </details>
 * ```
 *
 * Which is represented verbosely by a checkbox hack as such:
 *
 * ```html
 * <div>                                                 <!-- Container -->
 *     <input                                            <!-- Hidden checkbox -->
 *         type="checkbox"
 *         id="sidebar-checkbox"
 *         class="mw-checkbox-hack-checkbox"
 *         role="button"
 *         {{#visible}}checked{{/visible}}
 *         aria-labelledby="sidebar-button"
 *         aria-controls="sidebar">
 *     <label                                            <!-- Button -->
 *         id="sidebar-button"
 *         class="mw-checkbox-hack-button"
 *         for="sidebar-checkbox">
 *         Click to expand navigation menu
 *     </label>
 *     <ul id="sidebar" class="mw-checkbox-hack-target"> <!-- Target -->
 *         <li>Main page</li>
 *         <li>Random article</li>
 *         <li>Donate to Wikipedia</li>
 *     </ul>
 * </div>
 * ```
 *
 * Where the checkbox is the input, the label is the button, and the target is the unordered list.
 * Note the wrapping div container too.
 *
 * Consider the disparate pattern:
 *
 * ```html
 * <!-- ... -->
 * <!-- The only requirement is that the button and target can be described as a sibling to the
 *      checkbox. -->
 * <input
 *     type="checkbox"
 *     id="sidebar-checkbox"
 *     class="mw-checkbox-hack-checkbox"
 *     {{#visible}}checked{{/visible}}>
 * <!-- ... -->
 * <label
 *     id="sidebar-button"
 *     class="mw-checkbox-hack-button"
 *     for="sidebar-checkbox"
 *     role="button"
 *     aria-expanded="true||false"
 *     aria-controls="#sidebar">
 *     Toggle navigation menu
 * </label>
 * <!-- ... -->
 * <ul id="sidebar" class="mw-checkbox-hack-target">
 *     <li>Main page</li>
 *     <li>Random article</li>
 *     <li>Donate to Wikipedia</li>
 * </ul>
 * <!-- ... -->
 * ```
 *
 * Which is the same as the disclosure widget but without the enclosing container and the input only
 * needs to be a preceding sibling of the button and target. It's possible to bend the checkbox hack
 * further to allow the button and target to be at an arbitrary depth so long as a parent can be
 * described as a succeeding sibling of the input, but this requires a mixin implementation that
 * duplicates the rules for each relation selector.
 *
 * Exposed APIs should be considered stable. @ignore is used for JSDoc compatibility (see T138401).
 *
 * Accompanying checkbox hack styles are tracked in T252774.
 *
 * [0]: https://developer.mozilla.org/docs/Web/HTML/Element/details
 */

/**
 * Checkbox hack binding type.
 *
 * @class {Object} CheckboxHack
 * @property {Window}                window
 * @property {HTMLInputElement}      checkbox
 * @property {HTMLElement}           button
 * @property {CheckboxHackOptions}   options
 * @property {Function}              unbind
 * @property {Function}              [onChange]
 * @ignore
 */
// TODO JsDoc: @property {onChangeCallback} [onChange]

/**
 * Checkbox hack initialization options type.
 *
 * @class {Object} CheckboxHackOptions
 * @property {boolean} [noClickHandler]
 * @property {boolean} [noKeyHandler]
 * @property {boolean} [noDismissOnClickOutside]
 * @property {boolean} [noDismissOnFocusLoss]
 * @property {Node}    [autoHideElement]
 * @ignore
 */

/*
 * Checkbox hack state change callback.
 *
 * // TODO: enable for JsDoc, no @callback in jsduck
 * @callback onChangeCallback
 * @this  CheckboxHack
 * @param {Event} [event]
 * @ignore
 */

/**
 * Checkbox hack listener state.
 *
 * @class {Object} CheckboxHackListeners
 * @property {Function} [onUpdateAriaExpandedOnInput]
 * @property {Function} [onToggleOnClick]
 * @property {Function} [onDismissOnClickOutside]
 * @property {Function} [onDismissOnFocusLoss]
 * @ignore
 */
// TODO JsDoc: Change to @typedef when we switch to JSDoc
// TODO JsDoc: @typedef {EventListener | EventListenerObject} Function - instead of Function

/**
 * Update the `aria-expanded` (target visibility) attribute
 * then fire the onChange(event) callback with `this` set to the CheckboxHack instance.
 *
 * handleStateChange() is called on every state change, either by the user clicking the checkbox
 * or other event resulting in a programmatic change to the state.
 *
 * @param {CheckboxHack} self
 * @param {Event} event Triggering user event
 * @return {void}
 * @ignore
 */
function handleStateChange( self, event ) {
	self.button.setAttribute( 'aria-expanded', self.checkbox.checked.toString() );
	if ( self.onChange ) {
		// Last call, therefore no exception handler. Errors will wind up to the event loop.
		self.onChange( event );
	}
}

/**
 * Set the checkbox state, the `aria-expanded` attribute and call the onChange() callback.
 *
 * setCheckedState() is called when a user event on some element other than the checkbox
 * should result in changing the checkbox state.
 *
 * Programmatic changes to checkbox.checked do not trigger an 'input' or 'change' event,
 * therefore that cannot be used for this purpose, unless emulated.
 *
 * Per https://html.spec.whatwg.org/multipage/indices.html#event-input
 * Input event is fired at controls when the user changes the value.
 * Per https://html.spec.whatwg.org/multipage/input.html#checkbox-state-(type=checkbox):event-input
 * Fire an event named input at the element with the bubbles attribute initialized to true.
 * https://html.spec.whatwg.org/multipage/indices.html#event-change
 *
 * @param {CheckboxHack} self
 * @param {boolean} checked
 * @param {Event} event
 * @return {void}
 * @ignore
 */
function setCheckedState( self, checked, event ) {
	if ( self.checkbox.checked !== checked ) {
		self.checkbox.checked = checked;
		handleStateChange( self, event );
	}
}

/**
 * Returns true if the Event's target is an inclusive descendant of any the checkbox hack's
 * constituents (checkbox, button or controlled element).
 *
 * @param {CheckboxHack} self
 * @param {Node} target
 * @return {boolean}
 * @ignore
 */
function containsEventTarget( self, target ) {
	var autoHideElement = self.options.autoHideElement;
	return (
		self.checkbox.contains( target ) ||
		self.button.contains( target ) ||
		autoHideElement && autoHideElement.contains( target )
	);
}

/**
 * Dismiss the target when event is outside the checkbox, button, and target.
 * In simple terms this closes the target (menu, typically) when clicking somewhere else.
 *
 * @param {CheckboxHack} self
 * @param {Event} event
 * @return {void}
 * @ignore
 */
function dismissIfExternalEventTarget( self, event ) {
	if ( event.target instanceof Node && !containsEventTarget( self, event.target ) ) {
		setCheckedState( self, false, event );
	}
}

/**
 * Whenever the checkbox state changes, update the `aria-expanded` (target visibility) attribute
 * then fire the onChange() callback.
 *
 * @param {CheckboxHack} self
 * @param {CheckboxHackListeners} listeners
 * @return {void}
 * @ignore
 */
function bindHandleStateChange( self ) {
	var listener = handleStateChange.bind( undefined, self );
	// Whenever the checkbox state changes, update the `aria-expanded` state.
	self.checkbox.addEventListener( 'input', listener );
	return { onUpdateAriaExpandedOnInput: listener };
}

/**
 * Manually change the checkbox state to avoid a focus change when using a pointing device.
 *
 * @param {CheckboxHack} self
 * @return {CheckboxHackListeners}
 * @ignore
 */
function bindToggleOnClick( self ) {
	function listener( event ) {
		// Do not allow the browser to handle the checkbox. Instead, manually toggle it which does
		// not alter focus.
		event.preventDefault();
		setCheckedState( self, !self.checkbox.checked, event );
	}
	self.button.addEventListener( 'click', listener, true );
	return { onToggleOnClick: listener };
}

/**
 * Dismiss the target when clicking elsewhere and update the `aria-expanded` attribute based on
 * checkbox state (target visibility).
 *
 * @param {CheckboxHack} self
 * @return {CheckboxHackListeners}
 * @ignore
 */
function bindDismissOnClickOutside( self ) {
	var listener = dismissIfExternalEventTarget.bind( undefined, self );
	self.window.addEventListener( 'click', listener, true );
	return { onDismissOnClickOutside: listener };
}

/**
 * Dismiss the target when focusing elsewhere and update the `aria-expanded` attribute based on
 * checkbox state (target visibility).
 *
 * @param {CheckboxHack} self
 * @return {CheckboxHackListeners}
 * @ignore
 */
function bindDismissOnFocusLoss( self ) {
	// If focus is given to any element outside the target, dismiss the target. Setting a focusout
	// listener on the target would be preferable, but this interferes with the click listener.
	var listener = dismissIfExternalEventTarget.bind( undefined, self );
	self.window.addEventListener( 'focusin', listener, true );
	return { onDismissOnFocusLoss: listener };
}

/**
 * Free all set listeners.
 *
 * @param {Window} window
 * @param {HTMLInputElement} checkbox The underlying hidden checkbox that controls target
 *   visibility.
 * @param {HTMLElement} button The visible label icon associated with the checkbox. This button
 *   toggles the state of the underlying checkbox.
 * @param {CheckboxHackListeners} listeners
 * @return {void}
 * @ignore
 */
function unbind( window, checkbox, button, listeners ) {
	if ( listeners.onDismissOnFocusLoss ) {
		window.removeEventListener( 'focusin', listeners.onDismissOnFocusLoss );
	}
	if ( listeners.onDismissOnClickOutside ) {
		window.removeEventListener( 'click', listeners.onDismissOnClickOutside );
	}
	if ( listeners.onToggleOnClick ) {
		button.removeEventListener( 'click', listeners.onToggleOnClick );
	}
	if ( listeners.onUpdateAriaExpandedOnInput ) {
		checkbox.removeEventListener( 'input', listeners.onUpdateAriaExpandedOnInput );
	}
}

/**
 * CheckboxHack(...): class constructor. Binds the necessary event handlers.
 *
 * If `options.autoHideElement` is set then dismiss the target when
 * clicking or focusing outside the autoHideElement.
 * Updates the `aria-expanded` attribute whenever the checkbox state changes.
 * When tapping the button itself, clears the focus outline.
 *
 * This function calls the other bind* functions and is the only expected interaction.
 * Customization options: noClickHandler, noKeyHandler, autoHideElement.
 *
 * @constructor {Object} {CheckboxHack}
 * @param {Window}               window      Page context.
 * @param {HTMLInputElement}     checkbox    The underlying hidden checkbox that controls target
 *     visibility.
 * @param {HTMLElement}          button      The visible label icon associated with the checkbox.
 *     This button toggles the state of the underlying checkbox.
 * @param {CheckboxHackOptions|Function}  [options]    Initialization options for the binding.
 * @param {Function}             [onChange]  Event callback called when the checkbox state changes.
 * @ignore
 */
/*   [options.noClickHandler]   Set true to disable JS handling of click/touch on the checkbox.
 *     Touch event will focus the button.
 *   [options.noKeyHandler]     Set true to disable SPACE and ENTER key handling.
 *   [options.autoHideElement]  The controlled element (made visible) by the checkbox.
 *   [onChange]                 Event callback called when the checkbox state changes.
 *     (this: CheckboxHack, event?: Event) => void
 *     The original event triggering the change:
 *       'click' / 'keydown' / 'keyup' event on the button
 *       or 'input' event on the checkbox,
 *       or 'click' / 'focusin' event outside the `autoHideElement`.
 */
function CheckboxHack( window, checkbox, button, options, onChange ) {
	/* TODO JsDoc: @type {CheckboxHackListeners} */
	/** @type {Object} */
	var listeners = {};
	this.window = window;
	this.checkbox = checkbox;
	this.button = button;
	if ( typeof options === 'function' ) {
		onChange = options;
		options = null;
	}
	this.options = options = options || {};
	this.onChange = onChange;
	this.unbind = function checkboxHackUnbind() {
		unbind( window, checkbox, button, listeners );
		listeners = {}; // Release references.
	};

	listeners.onUpdateAriaExpandedOnInput = bindHandleStateChange( this ).onUpdateAriaExpandedOnInput;
	if ( !options.noClickHandler ) {
		listeners.onToggleOnClick = bindToggleOnClick( this ).onToggleOnClick;
	}
	if ( options.autoHideElement ) {
		listeners.onDismissOnClickOutside = bindDismissOnClickOutside( this ).onDismissOnClickOutside;
		listeners.onDismissOnFocusLoss = bindDismissOnFocusLoss( this ).onDismissOnFocusLoss;
	}
}

/**
 * Public API
 */
module.exports = CheckboxHack;
/* Deprecated internal API, used only by Vector, destined to be removed after Vector is updated. */
CheckboxHack.bindToggleOnClick = function ( checkbox, button ) {
	// Do all the initialization in the first call, ignore the rest.
	return new CheckboxHack( window, checkbox, button );
};

CheckboxHack.bindUpdateAriaExpandedOnInput = function () {
	// NOP
};

CheckboxHack.updateAriaExpanded = function () {
	// NOP
};

/* Unused internal API removed immediately, no deprecation.
	bindDismissOnClickOutside: bindDismissOnClickOutside,
	bindDismissOnFocusLoss: bindDismissOnFocusLoss,
	bind: bind,
	unbind: unbind
*/
