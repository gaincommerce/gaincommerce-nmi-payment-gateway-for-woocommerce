<?php
namespace APNMIPaymentGateway;

trait Single_Instance_Trait {
    
    protected static $instance = null;

    public static function get_instance() {

		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}
}