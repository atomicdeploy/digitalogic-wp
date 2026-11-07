<?php
/**
 * Reviewed manufacturer data for the Microchip ENC28J60 chip.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

$voltage_conditions = 'Industrial grade: -40 to +85 °C; commercial grade: 0 to +70 °C. Normal operating conditions.';
$tx_conditions      = 'Ethernet TX; VDD = 3.30 V, SCK = 10 MHz, SO, LEDA and LEDB open, ECON2 = 0.';
$idle_conditions    = 'Active without Ethernet TX; VDD = 3.30 V, LEDA and LEDB open, ECON2 = 0.';
$sleep_conditions   = 'Sleep; VDD = CS = 3.30 V, inputs tied to VDD or VSS, TA = 25 °C, ECON2 = 1.';
$fields             = array( 'parameter', 'qualifier', 'condition_key', 'magnitude', 'unit', 'conditions', 'source_locator' );

return array(
	'part'           => array(
		'slug'         => 'microchip-enc28j60',
		'manufacturer' => 'Microchip Technology',
		'part_number'  => 'ENC28J60',
	),
	'source'         => array(
		'document_key' => 'DS39662E',
		'title'        => 'Microchip ENC28J60 Data Sheet, revision E',
		'url'          => 'https://ww1.microchip.com/downloads/en/DeviceDoc/39662e.pdf',
	),
	'specifications' => array_map(
		static function ( array $row ) use ( $fields ): array {
			return array_combine( $fields, $row );
		},
		array(
			array( 'supply_voltage', 'min', 'operating', '3.10', 'V', $voltage_conditions, '§16.1, D001 (VDD)' ),
			array( 'supply_voltage', 'typ', 'operating', '3.30', 'V', $voltage_conditions, '§16.1, D001 (VDD)' ),
			array( 'supply_voltage', 'max', 'operating', '3.60', 'V', $voltage_conditions, '§16.1, D001 (VDD)' ),
			array( 'supply_current', 'typ', 'ethernet_tx', '160', 'mA', $tx_conditions, '§16.1, IDD' ),
			array( 'supply_current', 'max', 'ethernet_tx', '180', 'mA', $tx_conditions, '§16.1, IDD' ),
			array( 'supply_current', 'typ', 'active_no_tx', '120', 'mA', $idle_conditions, '§16.1, IDD' ),
			array( 'supply_current', 'typ', 'sleep', '1.2', 'mA', $sleep_conditions, '§16.1, IDDS' ),
			array( 'supply_current', 'max', 'sleep', '2.0', 'mA', $sleep_conditions, '§16.1, IDDS' ),
			array( 'packet_buffer', 'nominal', 'tx_rx_shared', '8192', 'B', 'Total shared transmit and receive packet SRAM.', '§1.0, overview; §3.2' ),
			array( 'ethernet_line_rate', 'nominal', '10base-t', '10', 'Mbit/s', '10BASE-T physical link rate.', '§1.0, overview' ),
		)
	),
);
