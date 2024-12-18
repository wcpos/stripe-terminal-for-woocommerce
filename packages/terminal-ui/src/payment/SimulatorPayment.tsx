import React from 'react';
import { Group } from '../components/Group/Group';
import { Text } from '../components/Text/Text';
import { TextInput } from '../components/TextInput/TextInput';
import { CheckBox } from '../components/CheckBox/CheckBox';
import { Select } from '../components/Select/Select';
import { Section } from '../components/Section/Section';
import { Button } from '../components/Button/Button';
import type { Terminal } from '@stripe/terminal-js';
import type { Client } from '../client';
import { Logger } from '../logger';

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

interface SimulatorPaymentProps {
	client: Client;
	terminal: Terminal;
}

const chargeAmount = 1000;
const taxAmount = 100;
const currency = 'aud';

export const SimulatorPayment = ({ client, terminal }: SimulatorPaymentProps) => {
	const [testCardNumber, setTestCardNumber] = React.useState('');
	const [testPaymentMethod, setTestPaymentMethod] = React.useState('visa');
	const [tipAmount, setTipAmount] = React.useState<number | null>(null);
	const [simulateOnReaderTip, setSimulateOnReaderTip] = React.useState(false);
	const [cancelablePayment, setCancelablePayment] = React.useState(false);
	const pendingPaymentIntentSecret = React.useRef<string | null>(null);

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
	};

	const handleCollectCardPayment = async () => {
		// We want to reuse the same PaymentIntent object in the case of declined charges, so we
		// store the pending PaymentIntent's secret until the payment is complete.
		if (!pendingPaymentIntentSecret.current) {
			try {
				let paymentMethodTypes = ['card_present'];
				if (currency === 'cad') {
					paymentMethodTypes.push('interac_present');
				}
				let createIntentResponse = await client.createPaymentIntent({
					amount: chargeAmount + taxAmount,
					currency: currency,
					description: 'Test Charge',
					paymentMethodTypes,
				});
				pendingPaymentIntentSecret.current = createIntentResponse.client_secret;
			} catch (e) {
				// Suppress backend errors since they will be shown in logs
				return;
			}
		}

		const simulatorConfiguration = {
			testPaymentMethod: testPaymentMethod,
			testCardNumber: testCardNumber,
		};

		if (simulateOnReaderTip) {
			simulatorConfiguration.tipAmount = Number(tipAmount);
		}

		// Read a card from the customer
		terminal.setSimulatorConfiguration(simulatorConfiguration);
		const paymentMethodPromise = terminal.collectPaymentMethod(pendingPaymentIntentSecret.current);
		setCancelablePayment(true);
		const result = await paymentMethodPromise;
		if (result.error) {
			Logger.logMessage(`Collect payment method failed: ${result.error.message}`, 'error');
		} else {
			const confirmResult = await terminal.processPayment(result.paymentIntent);
			// At this stage, the payment can no longer be canceled because we've sent the request to the network.
			setCancelablePayment(false);
			if (confirmResult.error) {
				alert(`Confirm failed: ${confirmResult.error.message}`);
			} else if (confirmResult.paymentIntent) {
				if (confirmResult.paymentIntent.status !== 'succeeded') {
					try {
						// Capture the PaymentIntent from your backend client and mark the payment as complete
						let captureResult = await client.capturePaymentIntent({
							paymentIntentId: confirmResult.paymentIntent.id,
						});
						pendingPaymentIntentSecret.current = null;
						Logger.logMessage('Payment Successful!', 'success');
						return captureResult;
					} catch (e) {
						// Suppress backend errors since they will be shown in logs
						return;
					}
				} else {
					pendingPaymentIntentSecret.current = null;
					Logger.logMessage('Single-message payment successful!', 'success');
					return confirmResult;
				}
			}
		}
	};

	const handleCancelPayment = async () => {
		await terminal.cancelCollectPaymentMethod();
		pendingPaymentIntentSecret.current = null;
		setCancelablePayment(false);
	};

	return (
		<Section>
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
							maxLength={16}
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
						<Text color="darkGrey" className="stwc-text-sm">
							Collect card payment
						</Text>
					</Button>
					<Button onClick={handleCancelPayment} disabled={!cancelablePayment}>
						<Text color="darkGrey" className="stwc-text-sm">
							Cancel payment
						</Text>
					</Button>
				</div>
			</Group>
		</Section>
	);
};
