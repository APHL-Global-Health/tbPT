<?php

class Application_Model_DbTable_DataManagers extends Zend_Db_Table_Abstract {

    protected $_name = 'data_manager';
    protected $_primary = array('dm_id');

    protected $_dependentTables = array('Application_Model_DbTable_ReadinessChecklistResponse');

    public function addUser($params) {

        $common = new Application_Service_Common();
        $email = $params['userId'];
        $plainPass = $common->generateRandomPassword(9);
        $password = MD5($plainPass);

        $authNameSpace = new Zend_Session_Namespace('administrators');
        $data = array(
            'first_name' => $params['fname'],
            'last_name' => $params['lname'],
            'institute' => $params['institute'],
            'phone' => $params['phone2'],
            'mobile' => $params['phone1'],
            'secondary_email' => $params['semail'],
            'primary_email' => $params['userId'],
            'password' => $password,
            'force_password_reset' => 1,
            'qc_access' => $params['qcAccess'],
            'enable_adding_test_response_date' => $params['receiptDateOption'],
            'enable_choosing_mode_of_receipt' => $params['modeOfReceiptOption'],
            'view_only_access' => $params['viewOnlyAccess'],
            'status' => $params['status'],
            'isTester' => $params['isTester'],
            'created_by' => $authNameSpace->admin_id,
            'created_on' => new Zend_Db_Expr('now()')
        );
        if (isset($_SESSION['loggedInDetails']["IsVl"])) {
            $data['IsVl'] = $_SESSION['loggedInDetails']["IsVl"];
        }

        $fullname = $data['first_name'] . ' ' . $data['last_name'];

        $common->sendPasswordEmailToUser($email, $plainPass, $fullname);
        return $this->insert($data);
    }

    public function getAllUsers($parameters) {

        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array('p.institute_name', 'u.first_name', 'u.last_name', 'u.mobile', 'u.primary_email', 'u.secondary_email', 'u.status', 'u.IsTester');


        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = "dm_id";


        /*
         * Paging
         */
        $sLimit = "";
        if (isset($parameters['iDisplayStart']) && $parameters['iDisplayLength'] != '-1') {
            $sOffset = $parameters['iDisplayStart'];
            $sLimit = $parameters['iDisplayLength'];
        }

        /*
         * Ordering
         */
        $sOrder = "";
        if (isset($parameters['iSortCol_0'])) {
            $sOrder = "";
            for ($i = 0; $i < intval($parameters['iSortingCols']); $i++) {
                if ($parameters['bSortable_' . intval($parameters['iSortCol_' . $i])] == "true") {
                    $sOrder .= $aColumns[intval($parameters['iSortCol_' . $i])] . "
				 	" . ( $parameters['sSortDir_' . $i] ) . ", ";
                }
            }

            $sOrder = substr_replace($sOrder, "", -2);
        }

        /*
         * Filtering
         * NOTE this does not match the built-in DataTables filtering which does it
         * word by word on any field. It's possible to do here, but concerned about efficiency
         * on very large tables, and MySQL's regex functionality is very limited
         */
        $sWhere = "";
        if (isset($parameters['sSearch']) && $parameters['sSearch'] != "") {
            $searchArray = explode(" ", $parameters['sSearch']);
            $sWhereSub = "";
            foreach ($searchArray as $search) {
                if ($sWhereSub == "") {
                    $sWhereSub .= "(";
                } else {
                    $sWhereSub .= " AND (";
                }
                $colSize = count($aColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $aColumns[$i] . " LIKE '%" . ($search ) . "%' OR ";
                    } else {
                        $sWhereSub .= $aColumns[$i] . " LIKE '%" . ($search ) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($aColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $aColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $aColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }


        /*
         * SQL queries
         * Get data to display
         */

        $sQuery = $this->getAdapter()->select()->from(array('u' => $this->_name))
                ->joinLeft(array('pmm' => 'participant_manager_map'), 'pmm.dm_id=u.dm_id', array('participant_id'))
                ->joinLeft(array('p' => 'participant'), 'p.participant_id = pmm.participant_id', array('p.participant_id', 'p.institute_name'))
                ->group('u.dm_id');

        if ($sWhere == "") {
            $sWhere .= "u.IsVl='" . $_SESSION['loggedInDetails']['IsVl'] . "' ";
        } else {
            $sWhere .= "and (u.IsVl='" . $_SESSION['loggedInDetails']['IsVl'] . "') ";
        }

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

//        die($sQuery);

        $rResult = $this->getAdapter()->fetchAll($sQuery);


        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $this->getAdapter()->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sQuery = $this->getAdapter()->select()->from($this->_name, new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"));
        $aResultTotal = $this->getAdapter()->fetchCol($sQuery);
        $iTotal = $aResultTotal[0];

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        foreach ($rResult as $aRow) {
            $row = array();

            $row[] = $aRow['institute_name'];
            $row[] = trim($aRow['first_name'] . " " . $aRow['last_name']);
            $row[] = $aRow['primary_email'];
            $row[] = $aRow['mobile'];

            $row[] = $aRow['IsTester'] == 0 ? 'Yes' : 'No';

            $row[] = $aRow['status'];

            $row[] = '<a href="/admin/data-managers/edit/id/' . $aRow['dm_id'] . '" class="btn btn-warning btn-xs"'
                    . ' style="margin-right: 2px;"><i class="icon-pencil"></i> Edit</a>';

            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function getUserDetails($userId) {
        return $this->fetchRow("primary_email = '" . $userId . "'")->toArray();
    }

    public function getUserRecords($userId) {
        return count($this->fetchRow("dm_id = $userId")->toArray());
    }

    public function getUserDetailsBySystemId($userSystemId) {
        return $this->fetchRow("dm_id = $userSystemId")->toArray();
    }

    public function updateUserLaboratory($params, $sendMessage = true) {
        $db = Zend_Db_Table_Abstract::getAdapter();
        $where = $params['dm_id'];

        $bind['participant_id'] = $params['participant_id'];
        $numberRows = $db->update('participant_manager_map', $bind, "dm_id = $where");
        $exists = $db->select()
                        ->from('participant_manager_map', ['hits' => new Zend_Db_Expr("COUNT('dm_id')")])
                        ->where('dm_id = ?',$params['dm_id'])
                        ->query()
                        ->fetchAll()[0]['hits'];

        if ($numberRows == 0) {
            $bind['dm_id'] = $params['dm_id'];
            if ($exists == 0) {
                $numberRows = $db->insert('participant_manager_map', $bind);
            }
            $message = '<br>You have added to a facility : ';
        } else {
            $message = '<br>Your facility has been changed to : ';
        }
        $participant = new Application_Model_DbTable_Participants();
        $partpnt = $participant->getParticipant($bind['participant_id']);

        $common = new Application_Service_Common();
        if ($numberRows == 1 && $sendMessage) {
            $email = $params['userId'];
            $common->sendGeneralEmail($email, $message . $partpnt["institute_name"]);
        }
    }

    public function updateUser($params) {
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $data = array(
            'first_name' => $params['fname'],
            'last_name' => $params['lname'],
            'phone' => $params['phone2'],
            'mobile' => $params['phone1'],
             'IsTester' => $params['IsTester'],
            'secondary_email' => $params['semail'],
            'updated_by' => $authNameSpace->admin_id,
            'updated_on' => new Zend_Db_Expr('now()')
        );

        if (isset($params['institute']) && $params['institute'] != "") {
            $data['institute'] = $params['institute'];
        }
        if (isset($params['qcAccess']) && $params['qcAccess'] != "") {
            $data['qc_access'] = $params['qcAccess'];
        }
        if (isset($params['receiptDateOption']) && $params['receiptDateOption'] != "") {
            $data['enable_adding_test_response_date'] = $params['receiptDateOption'];
        }
        if (isset($params['modeOfReceiptOption']) && $params['modeOfReceiptOption'] != "") {
            $data['enable_choosing_mode_of_receipt'] = $params['modeOfReceiptOption'];
        }
        if (isset($params['viewOnlyAccess']) && $params['viewOnlyAccess'] != "") {
            $data['view_only_access'] = $params['viewOnlyAccess'];
        }
        if (isset($params['userId']) && $params['userId'] != "") {
            $data['primary_email'] = $params['userId'];
        }
        if (isset($params['password']) && $params['password'] != "") {
            $data['password'] = $params['password'];
            $data['force_password_reset'] = 1;
        }
        if (isset($params['status']) && $params['status'] != "") {
            $data['status'] = $params['status'];
        }
        if (isset($_SESSION['loggedInDetails']["IsVl"])) {
            $data['IsVl'] = $_SESSION['loggedInDetails']["IsVl"];
        }
        return $this->update($data, "dm_id = " . $params['userSystemId']);
    }

    public function resetpasswordForEmail($email) {
        $row = $this->fetchRow("primary_email = '" . $email . "'");
        if ($row != null && count($row) == 1) {
            $randompassword = Application_Service_Common::getRandomString(10);
            $row->password = md5($randompassword);
            $row->force_password_reset = 1;
            $row->save();
            return $randompassword;
        } else {
            return false;
        }
    }

    public function getAllDataManagers($active = true) {
        $sql = $this->select()->order("first_name");
        if ($active) {
            if (isset($_SESSION['loggedInDetails']["IsVl"])) {
                $IsVl = $_SESSION['loggedInDetails']["IsVl"];
                $sql = $sql->where("status='active' and IsVl=$IsVl");
            } else {
                $sql = $sql->where("status='active'");
            }
        }
        return $this->fetchAll($sql);
    }

    public function updatePassword($oldpassword, $newpassword) {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        $email = $authNameSpace->email;
        $noOfRows = $this->update(array('password' => md5($newpassword), 'force_password_reset' => 0), "primary_email = '" . $email . "' and password = '" . md5($oldpassword) . "'");

        if ($noOfRows != null && count($noOfRows) == 1) {
            $authNameSpace->force_password_reset = 0;
            return true;
        } else {
            return false;
        }
    }

    public function updateLastLogin($dmId) {

        $noOfRows = $this->update(array('last_login' => new Zend_Db_Expr('now()')), "dm_id = " . $dmId);
        if ($noOfRows != null && count($noOfRows) == 1) {
            return true;
        } else {
            return false;
        }
    }

}
