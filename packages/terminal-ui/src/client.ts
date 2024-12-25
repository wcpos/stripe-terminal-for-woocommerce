export class Client {
	private url: string;

	constructor(url: string) {
		this.url = url;
		this.listLocations = this.listLocations.bind(this);
	}

	createConnectionToken(): Promise<any> {
		const body = JSON.stringify({});
		return this.doPost(`${this.url}/connection-token`, body);
	}

	registerDevice({
		label,
		registrationCode,
		location,
	}: {
		label: string;
		registrationCode: string;
		location: string;
	}): Promise<any> {
		const body = JSON.stringify({
			label,
			registrationCode,
			location,
		});
		return this.doPost(`${this.url}/register-reader`, body);
	}

	createPaymentIntent({ orderId }: { orderId: number }): Promise<any> {
		const body = JSON.stringify({
			order_id: orderId,
		});
		return this.doPost(`${this.url}/create-payment-intent`, body);
	}

	savePaymentMethodToCustomer({ paymentMethodId }: { paymentMethodId: string }): Promise<any> {
		const body = JSON.stringify({ payment_method_id: paymentMethodId });
		return this.doPost(`${this.url}/attach-payment-method-to-customer`, body);
	}

	async capturePaymentIntent({
		orderId,
		paymentIntent,
	}: {
		orderId: number;
		paymentIntent: Record<string, any>;
	}): Promise<any> {
		const body = JSON.stringify({
			order_id: orderId,
			payment_intent: paymentIntent,
		});
		return this.doPost(`${this.url}/capture-payment-intent`, body);
	}

	async listLocations(): Promise<any> {
		const response = await fetch(`${this.url}/list-locations`, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
			},
		});

		return this.handleResponse(response);
	}

	private async doPost(url: string, body: string): Promise<any> {
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: body,
		});

		return this.handleResponse(response);
	}

	private async handleResponse(response: Response): Promise<any> {
		if (response.ok) {
			return response.json();
		} else {
			// Try to parse JSON error details
			let errorDetail;
			try {
				errorDetail = await response.json();
			} catch {
				// Fall back to plain text if JSON parsing fails
				errorDetail = await response.text();
			}

			// Throw an error with detailed information
			throw new Error(
				typeof errorDetail === 'object'
					? JSON.stringify(errorDetail)
					: `Request Failed: ${errorDetail}`
			);
		}
	}
}
