import React from 'react';
import { Group } from '../components/Group/Group';
// import ReaderIcon from '../components/Icon/reader/ReaderIcon.jsx';
import { Section } from '../components/Section/Section';
import { Text } from '../components/Text/Text';
import { Button } from '../components/Button/Button';

export const ReaderList = ({ readers, discoveryInProgress, requestInProgress, onConnect }) => {
	if (discoveryInProgress) {
		return (
			<Text size={14} color="darkGrey">
				Discovering...
			</Text>
		);
	}

	if (readers.length >= 1) {
		return (
			<>
				{readers.map((reader, i) => {
					const isOffline = reader.status === 'offline';
					return (
						<Section position="middle" key={i}>
							<Group
								direction="row"
								alignment={{ justifyContent: 'space-between', alignItems: 'center' }}
							>
								<Group direction="row">
									<ReaderIcon />
									<Group direction="column">
										<Text>{reader.label}</Text>
										<Text size={11} color="darkGrey">
											{reader.serial_number}
										</Text>
									</Group>
								</Group>
								<Button
									disabled={isOffline || requestInProgress}
									color={isOffline || requestInProgress ? 'white' : 'primary'}
									onClick={() => onConnect(reader)}
								>
									<Text size={14} color={isOffline || requestInProgress ? 'darkGrey' : 'white'}>
										{isOffline ? 'Offline' : 'Connect'}
									</Text>
								</Button>
							</Group>
						</Section>
					);
				})}
			</>
		);
	}

	return (
		<>
			{/* <ReaderIcon /> */}
			<Text color="darkGrey" size={12}>
				Register a new reader, then discover readers on your account. You can also use the reader
				simulator provided by the SDK if you don't have hardware.
			</Text>
		</>
	);
};
