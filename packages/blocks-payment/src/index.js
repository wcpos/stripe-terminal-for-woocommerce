/**
 * External dependencies
 */
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getPaymentMethodData } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const PAYMENT_METHOD_NAME = 'stripe_terminal_for_woocommerce';
const settings = getPaymentMethodData( PAYMENT_METHOD_NAME, {} );
const defaultLabel = __(
	'Stripe Terminal',
	'stripe-terminal-for-woocommerce'
);
const label = decodeEntities( settings.title || '' ) || defaultLabel;

/**
 * Content shown when Stripe Terminal is selected in Blocks checkout.
 * Actual reader payment happens on the classic order-pay page after Place order.
 *
 * Description is rendered as plain text (not HTML) to match a safe third-party
 * pattern; classic checkout still uses wp_kses_post in payment_fields().
 */
const Content = () => {
	const description = decodeEntities( settings.description || '' );
	const note = __(
		'After placing your order you will complete payment on the card reader.',
		'stripe-terminal-for-woocommerce'
	);

	return (
		<div className="wc-stripe-terminal-blocks-content">
			{ description ? <p>{ description }</p> : null }
			<p>{ note }</p>
		</div>
	);
};

const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={ label } />;
};

registerPaymentMethod( {
	name: PAYMENT_METHOD_NAME,
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports ?? [ 'products' ],
	},
} );
