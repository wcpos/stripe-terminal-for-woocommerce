import React from 'react';
import { Group } from '../components/Group/Group';
import { Text } from '../components/Text/Text';
import { TextInput } from '../components/TextInput/TextInput';
import { CheckBox } from '../components/CheckBox/CheckBox';
import { Select } from '../components/Select/Select';
import { Button } from '../components/Button/Button';
import { Logger } from '../logger';
import { stwcConfig } from '../stwcConfig';
import type { Terminal } from '@stripe/terminal-js';
import type { Client } from '../client';

const middot = '\u00b7';
const placeholder = 'xxxx xxxx xxxx xxxx'.replace(/x/g, middot);

const testPaymentMethods = [
	{ label: 'visa', value: 'visa' },
	{ label: 'visa_debit', value: 'visa_debit' },
	{ label: 'mastercard', value: 'mastercard' },
	{ label: 'mastercard_debit', value: 'mastercard_debit' },
	{ label: 'mastercard_prepaid', value: 'mastercard_prepaid' },
	{ label: 'amex', value: 'amex' },
	{ label: 'amex2', value: 'amex2' },
	{ label: 'discover', value: 'discover' },
	{ label: 'discover2', value: 'discover2' },
	{ label: 'diners', value: 'diners' },
	{ label: 'diners_14digits', value: 'diners_14digits' },
	{ label: 'jcb', value: 'jcb' },
	{ label: 'unionpay', value: 'unionpay' },
	{ label: 'interac', value: 'interac' },
	{ label: 'refund_fail', value: 'refund_fail' },
	{ label: 'charge_declined', value: 'charge_declined' },
	{ label: 'charge_declined_insufficient_funds', value: 'charge_declined_insufficient_funds' },
	{ label: 'charge_declined_lost_card', value: 'charge_declined_lost_card' },
	{ label: 'charge_declined_stolen_card', value: 'charge_declined_stolen_card' },
	{ label: 'charge_declined_expired_card', value: 'charge_declined_expired_card' },
	{ label: 'charge_declined_processing_error', value: 'charge_declined_processing_error' },
];

const testPaymentMethodCardNumbers: Record<string, string> = {
	visa: '4242424242424242',
	visa_debit: '4000056655665556',
	mastercard: '5555555555554444',
	mastercard_debit: '5200828282828210',
	mastercard_prepaid: '5105105105105100',
	amex: '378282246310005',
	amex2: '371449635398431',
	discover: '6011000990139424',
	discover2: '6011981111111113',
	diners: '3056930009020004',
	diners_14digits: '36227206271667',
	jcb: '3566002020360505',
	unionpay: '6200000000000005',
	interac: '4506445006931933',
	refund_fail: '4000000000005126',
	charge_declined: '4000000000000002',
	charge_declined_insufficient_funds: '4000000000009995',
	charge_declined_lost_card: '4000000000009987',
	charge_declined_stolen_card: '4000000000009979',
	charge_declined_expired_card: '4000000000000063',
	charge_declined_processing_error: '4000000000000119',
};

interface SimulatorPaymentProps {
	client: Client;
	terminal: Terminal;
}

interface SimulatorConfig {
	testPaymentMethod: string;
	testCardNumber: string;
	tipAmount?: number;
}

export const SimulatorPayment = ({ client, terminal }: SimulatorPaymentProps) => {
	const { chargeAmount, taxAmount, currency, orderId } = stwcConfig;
	const [testCardNumber, setTestCardNumber] = React.useState(testPaymentMethodCardNumbers['visa']);
	const [testPaymentMethod, setTestPaymentMethod] = React.useState('visa');
	const [tipAmount, setTipAmount] = React.useState<number | null>(null);
	const [simulateOnReaderTip, setSimulateOnReaderTip] = React.useState(false);
	const [cancelablePayment, setCancelablePayment] = React.useState(false);
	const pendingPaymentIntentSecret = React.useRef<string | null>(null);

	const errorAlert = (error: unknown, fallbackMessage: string) => {
		let errorMessage = fallbackMessage;

		if (error instanceof Error) {
			// Attempt to parse the message as JSON if possible
			try {
				const parsedError = JSON.parse(error.message);
				errorMessage = parsedError.message || fallbackMessage;
			} catch {
				// If parsing fails, use the raw message
				errorMessage = error.message;
			}
		} else if (typeof error === 'string') {
			// Handle string errors, attempting JSON parsing
			try {
				const parsedError = JSON.parse(error);
				errorMessage = parsedError.message || fallbackMessage;
			} catch {
				errorMessage = error; // Fallback to raw string
			}
		}

		// Show the error message to the user
		alert(errorMessage);
	};

	const handleCardNumberChange = (value: string) => {
		setTestCardNumber(value);
	};

	const handleTipAmountChange = (value: number | null) => {
		setTipAmount(value);
	};

	const handleSimulateTipChange = () => {
		setSimulateOnReaderTip((prevState) => {
			if (prevState) {
				setTipAmount(null); // Reset tip amount when disabling
			}
			return !prevState;
		});
	};

	const handlePaymentMethodChange = (value: string) => {
		setTestPaymentMethod(value);
		setTestCardNumber(testPaymentMethodCardNumbers[value] || '');
	};

	const handleCollectCardPayment = async () => {
		if (chargeAmount === null || taxAmount === null || currency === null || orderId === null) {
			Logger.logMessage('Cannot proceed with payment: Invalid configuration.', 'error');
			alert('Cannot proceed with payment: Invalid configuration.');
			return;
		}

		// Create or reuse a PaymentIntent
		if (!pendingPaymentIntentSecret.current) {
			try {
				const paymentMethodTypes =
					currency === 'cad' ? ['card_present', 'interac_present'] : ['card_present'];
				const createIntentResponse = await client.createPaymentIntent({
					amount: chargeAmount + taxAmount,
					currency,
					description: 'Test Charge',
					paymentMethodTypes,
				});
				pendingPaymentIntentSecret.current = createIntentResponse.client_secret;
			} catch (error) {
				errorAlert(error, 'Failed to create payment intent.');
				return;
			}
		}

		const simulatorConfiguration: SimulatorConfig = {
			testPaymentMethod,
			testCardNumber,
		};
		if (simulateOnReaderTip) {
			simulatorConfiguration.tipAmount = Number(tipAmount);
		}

		// Read a card from the customer
		if (!pendingPaymentIntentSecret.current) return;

		try {
			terminal.setSimulatorConfiguration(simulatorConfiguration);
			const paymentMethodPromise = terminal.collectPaymentMethod(
				pendingPaymentIntentSecret.current
			);
			setCancelablePayment(true);

			const result = await paymentMethodPromise;

			if ('error' in result) {
				Logger.logMessage(`Collect payment method failed: ${result.error.message}`, 'error');
				alert(`Collect payment method failed: ${result.error.message}`);
				return;
			}

			// Confirm the payment
			const confirmResult = await terminal.processPayment(result.paymentIntent);
			setCancelablePayment(false);

			if ('error' in confirmResult) {
				errorAlert(confirmResult.error, 'Confirm failed.');
				return;
			}

			if (confirmResult.paymentIntent.status === 'succeeded') {
				// Capture the PaymentIntent via the client
				try {
					await client.capturePaymentIntent({
						paymentIntent: confirmResult.paymentIntent,
						orderId: orderId,
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
				}
			} else {
				alert('Payment failed to succeed.');
			}
		} catch (error) {
			errorAlert(error, 'An error occurred during payment collection.');
		} finally {
			setCancelablePayment(false);
		}
	};

	const handleCancelPayment = async () => {
		await terminal.cancelCollectPaymentMethod();
		pendingPaymentIntentSecret.current = null;
		setCancelablePayment(false);
	};

	return (
		<Group>
			<div className="stwc-flex stwc-flex-row stwc-justify-between stwc-items-center stwc-border-b stwc-border-gray-200 stwc-p-4">
				Simulator Payment
			</div>
			<div className="stwc-flex stwc-flex-col stwc-gap-4 stwc-p-4">
				<div className="stwc-flex stwc-flex-row stwc-justify-between stwc-items-center stwc-space-x-4">
					<Text color="darkGrey">Test Card Number</Text>
					<TextInput
						aria-label="Test Card Number"
						onChange={handleCardNumberChange}
						value={testCardNumber}
						placeholder={placeholder}
						className="stwc-w-64"
					/>
				</div>
				<div className="stwc-flex stwc-flex-row stwc-justify-between stwc-items-center stwc-space-x-4">
					<Text color="darkGrey">Test Payment Method</Text>
					<Select
						items={testPaymentMethods}
						value={testPaymentMethod}
						onChange={handlePaymentMethodChange}
					/>
				</div>
				<div className="stwc-flex stwc-flex-row stwc-justify-between stwc-items-center stwc-space-x-4">
					<Text color="darkGrey">Simulate on-reader tip?</Text>
					<CheckBox
						aria-label="Simulate on-reader tip?"
						checked={simulateOnReaderTip} // Use `checked` prop
						onChange={handleSimulateTipChange} // Toggle state correctly
					/>
				</div>
				{simulateOnReaderTip && (
					<div className="stwc-flex stwc-flex-row stwc-justify-between stwc-items-center stwc-space-x-4">
						<Text color="darkGrey">Tip Amount</Text>
						<TextInput
							aria-label="Tip Amount"
							onChange={(value) => handleTipAmountChange(Number(value) || null)}
							value={tipAmount !== null ? tipAmount.toString() : ''}
							type="number"
							min="0"
							step="1"
						/>
					</div>
				)}
			</div>

			{/* Actions */}
			<div className="stwc-flex stwc-flex-row stwc-gap-4 stwc-justify-center stwc-border-t stwc-border-gray-200 stwc-p-4">
				<Button onClick={handleCollectCardPayment}>
					<Text>Collect card payment</Text>
				</Button>
				<Button onClick={handleCancelPayment} disabled={!cancelablePayment}>
					<Text>Cancel payment</Text>
				</Button>
			</div>
		</Group>
	);
};
