/**
 * ErrorBoundary — AORM-13.6.
 *
 * Wraps React islands to catch render/lifecycle errors and display a
 * user-friendly fallback instead of a blank or broken page.
 *
 * Must be a class component; hooks cannot implement componentDidCatch.
 *
 * Usage:
 *   <ErrorBoundary>
 *     <MyComponent />
 *   </ErrorBoundary>
 *
 *   // Custom fallback:
 *   <ErrorBoundary fallback={ <p>Custom error message</p> }>
 *     <MyComponent />
 *   </ErrorBoundary>
 */

import { Component } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const ERROR_NOTICE_CLASS = 'aorm-error-boundary__notice';
export const RETRY_BUTTON_CLASS = 'aorm-error-boundary__retry';
export const ERROR_MESSAGE      = __(
	'Something went wrong. Please try again or reload the page.',
	'wicket-aorm'
);
export const RETRY_LABEL = __( 'Try Again', 'wicket-aorm' );

export default class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state       = { hasError: false, error: null };
		this.handleReset = this.handleReset.bind( this );
	}

	static getDerivedStateFromError( error ) {
		return { hasError: true, error };
	}

	componentDidCatch( error, info ) {
		console.error( '[AORM] ErrorBoundary caught an error:', error, info );
	}

	handleReset() {
		this.setState( { hasError: false, error: null } );
	}

	render() {
		const { hasError }         = this.state;
		const { children, fallback } = this.props;

		if ( ! hasError ) {
			return children;
		}

		if ( fallback !== undefined ) {
			return fallback;
		}

		return (
			<div className="aorm-error-boundary">
				<Notice
					className={ ERROR_NOTICE_CLASS }
					status="error"
					isDismissible={ false }
				>
					{ ERROR_MESSAGE }
				</Notice>
				<Button
					className={ RETRY_BUTTON_CLASS }
					variant="secondary"
					onClick={ this.handleReset }
					style={ { marginTop: '8px' } }
				>
					{ RETRY_LABEL }
				</Button>
			</div>
		);
	}
}
