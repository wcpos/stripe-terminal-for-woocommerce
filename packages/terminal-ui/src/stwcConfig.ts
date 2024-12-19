import { Logger } from './logger';
import { Client } from './client';

interface StwcConfig {
	chargeAmount: number | null;
	taxAmount: number | null;
	currency: string | null;
	restUrl: string | null;
	orderId: number | null;
	client: Client | null;
	terminal: any | null;
}

const rawConfig = (window as any).stwcConfig || {};

// Validate the configuration and log errors if needed
const validateConfig = (key: string, value: any, type: string): any => {
	if (typeof value !== type || (type === 'string' && !value.trim())) {
		Logger.logMessage(`Invalid or missing ${key} in stwcConfig.`, 'error');
		return null;
	}
	return value;
};

// Validate and extract config values
const chargeAmount = validateConfig('chargeAmount', rawConfig.chargeAmount, 'number');
const taxAmount = validateConfig('taxAmount', rawConfig.taxAmount, 'number');
const currency = validateConfig('currency', rawConfig.currency, 'string');
const restUrl = validateConfig('restUrl', rawConfig.restUrl, 'string');
const orderId = validateConfig('orderId', rawConfig.orderId, 'number');

// Initialize the Client
let client: Client | null = null;
if (restUrl) {
	client = new Client(restUrl);
	// Logger.logMessage('Client successfully initialized.', 'success');
} else {
	Logger.logMessage('Failed to initialize Client: restUrl is missing or invalid.', 'error');
}

// Initialize StripeTerminal
let terminal: any | null = null;
if (client && window.StripeTerminal) {
	try {
		terminal = window.StripeTerminal.create({
			onFetchConnectionToken: async () => {
				try {
					const connectionTokenResult = await client.createConnectionToken();
					return connectionTokenResult.secret;
				} catch (error) {
					Logger.logMessage(
						`Error fetching connection token: ${(error as Error).message}`,
						'error'
					);
					throw error;
				}
			},
			onUnexpectedReaderDisconnect: Logger.tracedFn(
				'onUnexpectedReaderDisconnect',
				'https://stripe.com/docs/terminal/js-api-reference#stripeterminal-create',
				async () => {
					Logger.logMessage('Unexpected disconnect from the reader.', 'error');
				}
			),
			onConnectionStatusChange: Logger.tracedFn(
				'onConnectionStatusChange',
				'https://stripe.com/docs/terminal/js-api-reference#stripeterminal-create',
				async (event) => {
					Logger.logMessage(`Connection status changed: ${event.status}`, 'info');
				}
			),
		});
		Logger.logMessage('Stripe Terminal successfully initialized.', 'success');
	} catch (error) {
		Logger.logMessage(`Failed to initialize Stripe Terminal: ${(error as Error).message}`, 'error');
	}
} else {
	if (!client) {
		Logger.logMessage('Stripe Terminal initialization skipped: Client is not available.', 'error');
	}
	if (!window.StripeTerminal) {
		Logger.logMessage(
			'Stripe Terminal initialization skipped: StripeTerminal is not available.',
			'error'
		);
	}
}

// Watch the client object and log method calls
if (client) {
	Logger.watchObject(client, 'backend', {
		createConnectionToken: { docsUrl: 'https://stripe.com/docs/terminal/sdk/js#connection-token' },
		registerDevice: {
			docsUrl: 'https://stripe.com/docs/terminal/readers/connecting/verifone-p400#register-reader',
		},
		createPaymentIntent: { docsUrl: 'https://stripe.com/docs/terminal/payments#create' },
		capturePaymentIntent: { docsUrl: 'https://stripe.com/docs/terminal/payments#capture' },
		savePaymentMethodToCustomer: {
			docsUrl: 'https://stripe.com/docs/terminal/payments/saving-cards',
		},
	});
}

// Watch the terminal object and log method calls
if (terminal) {
	Logger.watchObject(terminal, 'terminal', {
		discoverReaders: {
			docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#discover-readers',
		},
		connectReader: { docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#connect-reader' },
		disconnectReader: { docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#disconnect' },
		setReaderDisplay: {
			docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#set-reader-display',
		},
		collectPaymentMethod: {
			docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#collect-payment-method',
		},
		cancelCollectPaymentMethod: {
			docsUrl: 'https://stripe.com/docs/terminal/js-api-reference#cancel-collect-payment-method',
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

// Export the validated configuration and initialized instances
export const stwcConfig: StwcConfig = {
	chargeAmount,
	taxAmount,
	currency,
	restUrl,
	client,
	terminal,
	orderId,
};
