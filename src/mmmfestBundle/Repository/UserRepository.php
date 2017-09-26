<?php

namespace mmmfestBundle\Repository;

use \mmmfestBundle\Entity\User;

/**
 * UserRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UserRepository extends \Doctrine\ORM\EntityRepository
{
    /**
     * @param $user User|null
     *
     * @return string
     */
    public function getAccessLevelString($user)
    {
        if ($user) {
            if ($user->hasRole('ROLE_SUPER_ADMIN')) {
                return 'super_admin';
            } else if ($user->hasRole('ROLE_ADMIN')) {
                return 'admin';
            } else if ($user->hasRole('ROLE_MEMBER')) {
                return 'member';
            }
        }

        return 'anonymous';
    }

    public function getSuperAdminUsers(){
				return $this->createQueryBuilder('q')->select('q.email')->where("q.roles LIKE '%ROLE_SUPER_ADMIN%'")->getQuery()->getResult();
		}


}
