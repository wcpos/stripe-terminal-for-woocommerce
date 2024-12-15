// Utility to handle all API requests
export const apiRequest = async (endpoint: string, options?: RequestInit) => {
	if (!window.stwcConfig?.restUrl) {
		throw new Error('REST URL is not defined in stwcConfig.');
	}

	const response = await fetch(`${window.stwcConfig.restUrl}/${endpoint}`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		...options,
	});

	if (!response.ok) {
		throw new Error(`API Request Failed: ${response.statusText}`);
	}

	return response.json();
};

// Fetch connection token
export const fetchConnectionToken = async (): Promise<string> => {
	const { secret } = await apiRequest('connection-token', { method: 'POST' });
	return secret;
};

// Discover readers
export const discoverReaders = async (terminal: any, isSimulator: boolean) => {
	const result = await terminal.discoverReaders({ simulated: isSimulator });

	if (result.error) {
		throw new Error(result.error.message);
	}

	return result.discoveredReaders;
};

// Process payment
export const processPayment = async (paymentIntentId: string): Promise<any> => {
	return apiRequest('process-payment', {
		method: 'POST',
		body: JSON.stringify({ paymentIntentId }),
	});
};
