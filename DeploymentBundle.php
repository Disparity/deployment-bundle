<?php

namespace Disparity\DeploymentBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DeploymentBundle extends Bundle
{


	public function build(ContainerBuilder $container)
	{
		parent::build($container);

	}

}
