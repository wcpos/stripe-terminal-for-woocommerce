import React from 'react';
import type { Terminal } from '@stripe/terminal-js';
import type { Client } from '../client';
import { Logger } from '../logger';
import { stwcConfig } from '../stwcConfig';

interface UseCollectPaymentArgs {
	client: Client;
	terminal: Terminal;
	moto?: boolean;
}

interface CreatePaymentIntentResponse {
	client_secret: string;
}

type ErrorType = unknown;

export function useCollectPayment({ client, terminal, moto = false }: UseCollectPaymentArgs) {
	const { orderId } = stwcConfig;
	const [showPaymentOptions, setShowPaymentOptions] = React.useState(true);
	const [paymentProgress, setPaymentProgress] = React.useState<string | null>(null);
	const [cancelablePayment, setCancelablePayment] = React.useState(false);

	const pendingPaymentIntentSecret = React.useRef<string | null>(null);
	const pendingPaymentIntentMoto = React.useRef<boolean | null>(null);

	const errorAlert = (error: ErrorType, fallbackMessage: string) => {
		let errorMessage = fallbackMessage;

		if (error instanceof Error) {
			try {
				const parsedError = JSON.parse(error.message);
				errorMessage = parsedError.message || fallbackMessage;
			} catch {
				errorMessage = error.message;
			}
		} else if (typeof error === 'string') {
			try {
				const parsedError = JSON.parse(error);
				errorMessage = parsedError.message || fallbackMessage;
			} catch {
				errorMessage = error;
			}
		}

		alert(errorMessage);
	};

	const handleCollectCardPayment = React.useCallback(async () => {
		if (!orderId) {
			Logger.logMessage('Cannot proceed with payment: Invalid configuration.', 'error');
			errorAlert('Cannot proceed with payment: Invalid configuration.', 'Invalid configuration.');
			return;
		}

		setShowPaymentOptions(false);
		setPaymentProgress('Initializing payment...');

		// Invalidate cached intent if MOTO mode changed
		if (
			pendingPaymentIntentSecret.current &&
			pendingPaymentIntentMoto.current !== null &&
			pendingPaymentIntentMoto.current !== moto
		) {
			pendingPaymentIntentSecret.current = null;
		}

		if (!pendingPaymentIntentSecret.current) {
			try {
				const createIntentResponse = (await client.createPaymentIntent({
					orderId,
					moto,
				})) as CreatePaymentIntentResponse;
				pendingPaymentIntentSecret.current = createIntentResponse.client_secret;
				pendingPaymentIntentMoto.current = moto;
				setPaymentProgress('Payment intent created.');
			} catch (error) {
				errorAlert(error, 'Failed to create payment intent.');
				setPaymentProgress('Failed to create payment intent.');
				return;
			}
		}

		if (!pendingPaymentIntentSecret.current) return;

		try {
			setPaymentProgress('Waiting for card input...');
			setCancelablePayment(true);
			const paymentMethodResult = await terminal.collectPaymentMethod(
				pendingPaymentIntentSecret.current
			);
			if ('error' in paymentMethodResult) {
				Logger.logMessage(
					`Collect payment method failed: ${paymentMethodResult.error.message}`,
					'error'
				);
				errorAlert(paymentMethodResult.error, 'Collect payment method failed.');
				setPaymentProgress(`Error: ${paymentMethodResult.error.message}`);
				return;
			}

			setPaymentProgress('Processing payment...');
			const confirmResult = await terminal.processPayment(paymentMethodResult.paymentIntent);
			setCancelablePayment(false);

			if ('error' in confirmResult) {
				errorAlert(confirmResult.error, 'Confirm failed.');
				setPaymentProgress('Payment confirmation failed.');
				return;
			}

			if (confirmResult.paymentIntent.status === 'succeeded') {
				setPaymentProgress('Payment succeeded!');
				try {
					await client.capturePaymentIntent({
						paymentIntent: confirmResult.paymentIntent,
						orderId,
					});

					// Trigger the WooCommerce place_order button
					const placeOrderButton = document.getElementById('place_order');
					if (placeOrderButton) {
						const event = new MouseEvent('click', {
							bubbles: true,
							cancelable: true,
							view: window,
						});
						placeOrderButton.dispatchEvent(event);
					}
				} catch (error) {
					errorAlert(error, 'Failed to capture payment intent.');
					setPaymentProgress('Failed to capture payment intent.');
				}
			} else {
				setPaymentProgress('Payment failed to succeed.');
			}
		} catch (error) {
			errorAlert(error, 'An error occurred during payment collection.');
			setPaymentProgress('An error occurred during payment collection.');
			setShowPaymentOptions(true);
		} finally {
			setCancelablePayment(false);
		}
	}, [client, terminal, orderId, moto]);

	const handleCancelPayment = React.useCallback(async () => {
		await terminal.cancelCollectPaymentMethod();
		pendingPaymentIntentSecret.current = null;
		pendingPaymentIntentMoto.current = null;
		setCancelablePayment(false);
		setShowPaymentOptions(true);
	}, [terminal]);

	return {
		showPaymentOptions,
		paymentProgress,
		cancelablePayment,
		handleCollectCardPayment,
		handleCancelPayment,
	};
}
