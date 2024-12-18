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

	createPaymentIntent({
		amount,
		currency,
		description,
		paymentMethodTypes,
	}: {
		amount: number;
		currency: string;
		description: string;
		paymentMethodTypes: string[];
	}): Promise<any> {
		const body = JSON.stringify({
			amount,
			currency,
			description,
			payment_method_types: paymentMethodTypes,
		});
		return this.doPost(`${this.url}/create-payment-intent`, body);
	}

	capturePaymentIntent({ paymentIntentId }: { paymentIntentId: string }): Promise<any> {
		const body = JSON.stringify({ payment_intent_id: paymentIntentId });
		return this.doPost(`${this.url}/capture-payment-intent`, body);
	}

	savePaymentMethodToCustomer({ paymentMethodId }: { paymentMethodId: string }): Promise<any> {
		const body = JSON.stringify({ payment_method_id: paymentMethodId });
		return this.doPost(`${this.url}/attach-payment-method-to-customer`, body);
	}

	async listLocations(): Promise<any> {
		const response = await fetch(`${this.url}/list-locations`, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
			},
		});

		if (response.ok) {
			return response.json();
		} else {
			const text = await response.text();
			throw new Error('Request Failed: ' + text);
		}
	}

	private async doPost(url: string, body: string): Promise<any> {
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: body,
		});

		if (response.ok) {
			return response.json();
		} else {
			const text = await response.text();
			throw new Error('Request Failed: ' + text);
		}
	}
}
