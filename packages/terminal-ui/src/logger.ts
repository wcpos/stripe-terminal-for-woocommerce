type Collector = {
	collect: (log: LogEntry) => void;
};

export type LogEntry = {
	id: string;
	start_time_ms: number;
	method?: string; // Optional, used for structured logs
	type?: 'info' | 'success' | 'error' | 'warning'; // Type for plain text logs
	docsUrl?: string | null;
	request: string;
	response?: string | null;
	exception?: string | null;
};

type MethodMetadata = {
	[methodName: string]: {
		docsUrl: string;
	};
};

export class Logger {
	static collectors: Collector[] = [];
	static serializer: Promise<void> = Promise.resolve();

	static setCollectors(collectors: Collector[]): void {
		this.collectors = collectors;
	}

	static async forwardToCollectors(log: LogEntry): Promise<void> {
		this.collectors.forEach((collector) => collector.collect(log));
	}

	static logMessage(
		message: string,
		type: 'info' | 'success' | 'error' | 'warning' = 'info'
	): void {
		const simpleLog: LogEntry = {
			id: Math.floor(Math.random() * 10000000).toString(),
			start_time_ms: new Date().valueOf(),
			type,
			request: message,
		};
		Logger.forwardToCollectors(simpleLog);
	}

	static log(log: LogEntry): void {
		Logger.forwardToCollectors(log);
	}

	static tracedFn<T extends (...args: any[]) => Promise<any>>(
		methodName: string,
		docsUrl: string | null,
		fn: T
	): (...args: Parameters<T>) => Promise<ReturnType<T>> {
		return async function (this: unknown, ...args: Parameters<T>): Promise<ReturnType<T>> {
			const method = methodName || fn.name;
			const requestString = JSON.stringify(args);
			const UUID = Math.floor(Math.random() * 10000000).toString();
			const trace: LogEntry = {
				id: UUID,
				start_time_ms: new Date().valueOf(),
				method: method,
				docsUrl: docsUrl,
				request: requestString,
				response: null,
				exception: null,
			};

			try {
				const response = await fn.apply(this, args);
				trace.response = JSON.stringify(response);
				return response;
			} catch (e: any) {
				trace.exception = e.message;
				throw e;
			} finally {
				Logger.log(trace);
			}
		};
	}

	static watchObject(
		obj: Record<string, any>,
		objName: string,
		methodsMetadata: MethodMetadata
	): void {
		const objPrototype = Object.getPrototypeOf(obj);

		Object.getOwnPropertyNames(objPrototype)
			.filter((property) => methodsMetadata[property] !== undefined)
			.forEach((instanceMethodName) => {
				obj[instanceMethodName] = Logger.tracedFn(
					`${objName}.${instanceMethodName}`,
					methodsMetadata[instanceMethodName].docsUrl,
					objPrototype[instanceMethodName]
				);
			});
	}
}
