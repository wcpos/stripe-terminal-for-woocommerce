export class Client {
	private url: string;

	constructor(url: string) {
		this.url = url;
		this.listLocations = this.listLocations.bind(this);
	}

	createConnectionToken(): Promise<any> {
		const formData = new URLSearchParams();
		return this.doPost(`${this.url}/connection-token`, formData);
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
		const formData = new URLSearchParams();
		formData.append('label', label);
		formData.append('registration_code', registrationCode);
		formData.append('location', location);
		return this.doPost(`${this.url}/register-reader`, formData);
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
		const formData = new URLSearchParams();
		formData.append('amount', amount.toString());
		formData.append('currency', currency);
		formData.append('description', description);
		paymentMethodTypes.forEach((type) => formData.append('payment_method_types[]', type));
		return this.doPost(`${this.url}/create-payment-intent`, formData);
	}

	capturePaymentIntent({ paymentIntentId }: { paymentIntentId: string }): Promise<any> {
		const formData = new URLSearchParams();
		formData.append('payment_intent_id', paymentIntentId);
		return this.doPost(`${this.url}/capture-payment-intent`, formData);
	}

	savePaymentMethodToCustomer({ paymentMethodId }: { paymentMethodId: string }): Promise<any> {
		const formData = new URLSearchParams();
		formData.append('payment_method_id', paymentMethodId);
		return this.doPost(`${this.url}/attach-payment-method-to-customer`, formData);
	}

	async listLocations(): Promise<any> {
		const response = await fetch(`${this.url}/list-locations`, {
			method: 'get',
		});

		if (response.ok) {
			return response.json();
		} else {
			const text = await response.text();
			throw new Error('Request Failed: ' + text);
		}
	}

	private async doPost(url: string, body: URLSearchParams): Promise<any> {
		const response = await fetch(url, {
			method: 'post',
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
