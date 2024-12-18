import React from 'react';
import { Readers } from './readers/Readers';
import { Client } from './client';
import { Logs } from './logs/Logs';
import { Logger } from './logger';
import type { Terminal, Reader } from '@stripe/terminal-js';

export const App: React.FC = () => {
	const [terminal, setTerminal] = React.useState<Terminal | null>(null);
	const [client, setClient] = React.useState<Client | null>(null);
	const [connectionStatus, setConnectionStatus] = React.useState<string>('not_connected');
	const [reader, setReader] = React.useState<Reader | null>(null);

	/** Function to initialize the Client */
	const initializeClient = (url: string) => {
		const newClient = new Client(url);
		setClient(newClient);
		return newClient;
	};

	/** Function to initialize StripeTerminal */
	const initializeTerminal = (newClient: any) => {
		if (!window.StripeTerminal) {
			Logger.logMessage('Stripe Terminal is not available on the window object.', 'error');
			return;
		}

		return window.StripeTerminal.create({
			onFetchConnectionToken: async () => {
				const connectionTokenResult = await newClient.createConnectionToken();
				return connectionTokenResult.secret;
			},
			onUnexpectedReaderDisconnect: Logger.tracedFn(
				'onUnexpectedReaderDisconnect',
				'https://stripe.com/docs/terminal/js-api-reference#stripeterminal-create',
				async () => {
					Logger.logMessage('Unexpected disconnect from the reader', 'error');
					setConnectionStatus('not_connected');
					setReader(null);
				}
			),
			onConnectionStatusChange: Logger.tracedFn(
				'onConnectionStatusChange',
				'https://stripe.com/docs/terminal/js-api-reference#stripeterminal-create',
				async (event) => {
					Logger.logMessage(`Connection status changed: ${event.status}`, 'info');
					setConnectionStatus(event.status);
					setReader(null);
				}
			),
		});
	};

	/** useEffect: Initialize Client and Terminal */
	React.useEffect(() => {
		if (!window.stwcConfig?.restUrl) {
			Logger.logMessage('REST URL is not defined in stwcConfig.', 'error');
			return;
		}

		const clientInstance = initializeClient(window.stwcConfig.restUrl);
		if (!window.StripeTerminal) {
			Logger.logMessage('Stripe Terminal is not available on the window object.', 'error');
			return;
		}

		const terminalInstance = initializeTerminal(clientInstance);

		setTerminal(terminalInstance);
		Logger.logMessage('Stripe Terminal initialized.', 'success');
	}, []);

	/** Watch Client */
	React.useEffect(() => {
		if (client) {
			Logger.watchObject(client, 'backend', {
				createConnectionToken: {
					docsUrl: 'https://stripe.com/docs/terminal/sdk/js#connection-token',
				},
				registerDevice: {
					docsUrl:
						'https://stripe.com/docs/terminal/readers/connecting/verifone-p400#register-reader',
				},
				createPaymentIntent: { docsUrl: 'https://stripe.com/docs/terminal/payments#create' },
				capturePaymentIntent: { docsUrl: 'https://stripe.com/docs/terminal/payments#capture' },
				savePaymentMethodToCustomer: {
					docsUrl: 'https://stripe.com/docs/terminal/payments/saving-cards',
				},
			});
		}
	}, [client]);

	/** Watch Terminal */
	React.useEffect(() => {
		if (terminal) {
			Logger.watchObject(terminal, 'terminal', {
				discoverReaders: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#discover-readers',
				},
				connectReader: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#connect-reader',
				},
				disconnectReader: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#disconnect',
				},
				setReaderDisplay: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#set-reader-display',
				},
				collectPaymentMethod: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#collect-payment-method',
				},
				cancelCollectPaymentMethod: {
					docsUrl:
						'https://stripe.com/docs/terminal/js-api-reference#cancel-collect-payment-method',
				},
				processPayment: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#process-payment',
				},
				readReusableCard: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#read-reusable-card',
				},
				cancelReadReusableCard: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#cancel-read-reusable-card',
				},
				collectRefundPaymentMethod: {
					docsUrl:
						'https://stripe.com/docs/terminal/js-api-reference#stripeterminal-collectrefundpaymentmethod',
				},
				processRefund: {
					docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#stripeterminal-processrefund',
				},
				cancelCollectRefundPaymentMethod: {
					docsUrl:
						'https://stripe.com/docs/terminal/js-api-reference#stripeterminal-cancelcollectrefundpaymentmethod',
				},
			});
		}
	}, [terminal]);

	return (
		<div className="stwc-p-4">
			<Readers terminal={terminal} client={client} setReader={setReader} />
			<Logs />
		</div>
	);
};
