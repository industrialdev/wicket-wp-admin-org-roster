/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./resources/js/components/RosterActivity.js"
/*!***************************************************!*\
  !*** ./resources/js/components/RosterActivity.js ***!
  \***************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ RosterActivity)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__);
/**
 * Roster Activity tab — AORM-4, Tab 3 (nice-to-have).
 *
 * Scoped audit trail for this org membership. Read-only, paginated.
 * Columns: date/time, actor, activity type, summary, status.
 * Filters: activity type, date range, actor, status.
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */




function RosterActivity({
  orgUuid,
  membershipUuid
}) {
  // TODO (AORM-4, nice-to-have): implement scoped activity log.
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
    status: "info",
    isDismissible: false,
    children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Roster Activity log — coming soon.', 'wicket-aorm')
  });
}

/***/ },

/***/ "./resources/js/components/RosterAssignment.js"
/*!*****************************************************!*\
  !*** ./resources/js/components/RosterAssignment.js ***!
  \*****************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ RosterAssignment)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__);
/**
 * Roster Assignment tab — AORM-4, Tab 1.
 *
 * Table of current roster members with bulk and per-row actions.
 * Columns: name, email, relationship/assignment type, roles, status.
 *
 * Bulk actions: Remove person(s), Add role(s), Remove role(s).
 * Per-row: "Edit Permissions" opens a Modal with CheckboxControls.
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */




function RosterAssignment({
  orgUuid,
  membershipUuid
}) {
  // TODO (AORM-4.3 – 4.9): implement member table, bulk actions, Edit Permissions modal.
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
    status: "info",
    isDismissible: false,
    children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Roster Assignment — coming in AORM-4.', 'wicket-aorm')
  });
}

/***/ },

/***/ "./resources/js/components/RosterHeading.js"
/*!**************************************************!*\
  !*** ./resources/js/components/RosterHeading.js ***!
  \**************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ RosterHeading)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _css_roster_heading_css__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../css/roster-heading.css */ "./resources/css/roster-heading.css");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__);
/**
 * RosterHeading — always-visible heading block for the Roster Detail page.
 *
 * Displays org + membership metadata fetched from the AORM-4.1 REST endpoint
 * (GET /wicket-aorm/v1/rosters/{org_uuid}/{membership_uuid}) plus an external
 * link to the membership record in MDP.
 *
 * The MDP link URL is constructed from window.aormContext.appEndpoint
 * (injected server-side by Assets.php via wp_localize_script) so the
 * component never needs to call a separate API.
 *
 * @see AORM-4.2
 */





/**
 * Build the MDP membership admin URL.
 *
 * @param {string} appEndpoint    - MDP admin base URL (no trailing slash).
 * @param {string} orgUuid        - Organization UUID.
 * @param {string} membershipUuid - Membership UUID.
 * @returns {string|null} Full URL or null when any argument is empty.
 */

function buildMdpUrl(appEndpoint, orgUuid, membershipUuid) {
  if (!appEndpoint || !orgUuid || !membershipUuid) {
    return null;
  }
  return `${appEndpoint}/organizations/${encodeURIComponent(orgUuid)}/memberships/${encodeURIComponent(membershipUuid)}`;
}

/**
 * Format the roster count for display.
 *
 * @param {number}  assignedCount        - Number of assigned members.
 * @param {number}  maxAssignments       - Seat cap (ignored when unlimited).
 * @param {boolean} unlimitedAssignments - True when MDP has no seat cap.
 * @returns {string}
 */
function formatRosterCount(assignedCount, maxAssignments, unlimitedAssignments) {
  const max = unlimitedAssignments ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Unlimited', 'wicket-aorm') : String(maxAssignments);
  return `${assignedCount} / ${max}`;
}

/**
 * @param {Object}      props
 * @param {Object|null} props.roster - Normalized roster object from the REST API.
 */
function RosterHeading({
  roster
}) {
  if (!roster) {
    return null;
  }
  const appEndpoint = (window.aormContext ?? {}).appEndpoint ?? '';
  const mdpUrl = buildMdpUrl(appEndpoint, roster.org_uuid, roster.membership_uuid);
  const fields = [{
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Organization UUID', 'wicket-aorm'),
    value: roster.org_uuid
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Organization Type', 'wicket-aorm'),
    value: roster.org_type || '—'
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Membership UUID', 'wicket-aorm'),
    value: roster.membership_uuid || '—'
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Membership Tier', 'wicket-aorm'),
    value: roster.membership_tier || '—'
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Membership Owner', 'wicket-aorm'),
    value: roster.membership_owner || '—'
  }, {
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Current Roster count', 'wicket-aorm'),
    value: formatRosterCount(roster.assigned_count, roster.max_assignments, roster.unlimited_assignments)
  }];
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("div", {
    className: "aorm-roster-heading",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("div", {
      className: "aorm-roster-heading__org-name",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.__experimentalHeading, {
        children: roster.org_name
      })
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("div", {
      className: "aorm-roster-heading__meta-box",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("div", {
        className: "aorm-roster-heading__fields",
        children: fields.map(({
          label,
          value
        }) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("p", {
          className: "aorm-roster-heading__field",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("span", {
            className: "aorm-roster-heading__field-label",
            children: [label, ":"]
          }), ' ', value]
        }, label))
      }), mdpUrl && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("div", {
        className: "aorm-roster-heading__mdp-link",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
          variant: "secondary",
          href: mdpUrl,
          target: "_blank",
          rel: "noreferrer noopener",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('View in MDP', 'wicket-aorm')
        })
      })]
    })]
  });
}

/***/ },

/***/ "./resources/js/components/RosterUpload.js"
/*!*************************************************!*\
  !*** ./resources/js/components/RosterUpload.js ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ RosterUpload)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__);
/**
 * Roster Upload tab — AORM-4, Tab 2.
 *
 * Houses the multi-step bulk upload wizard (AORM-6 – 9) and individual
 * add flow (AORM-5). Steps: file drop → validation review → sync.
 *
 * @param {{ orgUuid: string, membershipUuid: string }} props
 */




function RosterUpload({
  orgUuid,
  membershipUuid
}) {
  // TODO (AORM-5 – 9): implement upload wizard (DropZone → validation → sync).
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
    status: "info",
    isDismissible: false,
    children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Roster Upload wizard — coming in AORM-5 through 9.', 'wicket-aorm')
  });
}

/***/ },

/***/ "./resources/js/hooks/useRestApi.js"
/*!******************************************!*\
  !*** ./resources/js/hooks/useRestApi.js ***!
  \******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   useRestApi: () => (/* binding */ useRestApi)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _utils_apiFetch__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../utils/apiFetch */ "./resources/js/utils/apiFetch.js");
/**
 * useRestApi — generic data-fetching hook for AORM REST endpoints.
 *
 * Wraps @wordpress/api-fetch so all requests automatically carry the WP
 * nonce and REST root injected by Assets.php via wp_localize_script().
 *
 * @example
 * const { data, isLoading, error, refresh } = useRestApi( '/wicket-aorm/v1/rosters' );
 */




/**
 * @typedef {Object} UseRestApiResult
 * @property {any}      data       Parsed response body, or null while loading.
 * @property {boolean}  isLoading  True on the initial fetch and any refresh.
 * @property {string|null} error   Error message if the request failed.
 * @property {Function} refresh    Re-fetch the endpoint on demand.
 */

/**
 * @param {string} path - REST API path relative to the WP REST root.
 * @returns {UseRestApiResult}
 */
function useRestApi(path) {
  const [data, setData] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [isLoading, setIsLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const fetchData = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    setIsLoading(true);
    setError(null);
    (0,_utils_apiFetch__WEBPACK_IMPORTED_MODULE_1__.apiFetch)({
      path
    }).then(response => {
      setData(response);
    }).catch(err => {
      setError(err?.message ?? 'An unexpected error occurred.');
    }).finally(() => {
      setIsLoading(false);
    });
  }, [path]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    fetchData();
  }, [fetchData]);
  return {
    data,
    isLoading,
    error,
    refresh: fetchData
  };
}

/***/ },

/***/ "./resources/js/pages/GroupRosters.js"
/*!********************************************!*\
  !*** ./resources/js/pages/GroupRosters.js ***!
  \********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ GroupRosters)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__);
/**
 * Group Rosters page.
 *
 * Mounted into #aorm-group-rosters by index.js.
 */




function GroupRosters() {
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("div", {
    className: "aorm-page aorm-page--group-rosters",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("h1", {
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Group Rosters', 'wicket-aorm')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
      status: "info",
      isDismissible: false,
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Group Roster management is coming soon.', 'wicket-aorm')
    })]
  });
}

/***/ },

/***/ "./resources/js/pages/OrgRosterDetail.js"
/*!***********************************************!*\
  !*** ./resources/js/pages/OrgRosterDetail.js ***!
  \***********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ OrgRosterDetail)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _hooks_useRestApi__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../hooks/useRestApi */ "./resources/js/hooks/useRestApi.js");
/* harmony import */ var _components_RosterHeading__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../components/RosterHeading */ "./resources/js/components/RosterHeading.js");
/* harmony import */ var _components_RosterAssignment__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../components/RosterAssignment */ "./resources/js/components/RosterAssignment.js");
/* harmony import */ var _components_RosterUpload__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ../components/RosterUpload */ "./resources/js/components/RosterUpload.js");
/* harmony import */ var _components_RosterActivity__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ../components/RosterActivity */ "./resources/js/components/RosterActivity.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__);
/**
 * Org Roster Detail page — AORM-4.
 *
 * Mounted into #aorm-roster-detail by index.js. Reads org_uuid and
 * membership_uuid from window.aormContext (injected server-side by
 * Assets.php via wp_localize_script), falling back to the URL query
 * string if those values are absent.
 *
 * Tabs (per AORM-4):
 *   1. Roster Assignment  — current members table with bulk/row actions
 *   2. Roster Upload      — bulk upload wizard + individual add (AORM-5 – 9)
 *   3. Roster Activity    — scoped audit trail (nice-to-have, AORM-4)
 */









function OrgRosterDetail() {
  // org_uuid and membership_uuid are read from $_GET in PHP (Assets.php) and
  // injected here via wp_localize_script → window.aormContext.
  const context = window.aormContext ?? {};
  const orgUuid = context.orgUuid ?? null;
  const membershipUuid = context.membershipUuid ?? null;
  const {
    data: roster,
    isLoading,
    error
  } = (0,_hooks_useRestApi__WEBPACK_IMPORTED_MODULE_2__.useRestApi)(orgUuid && membershipUuid ? `/wicket-aorm/v1/rosters/${orgUuid}/${membershipUuid}` : null);
  if (!orgUuid || !membershipUuid) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
      status: "error",
      isDismissible: false,
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Missing org_uuid or membership_uuid URL parameters.', 'wicket-aorm')
    });
  }
  if (isLoading) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Spinner, {});
  }
  if (error) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
      status: "error",
      isDismissible: false,
      children: error
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)("div", {
    className: "aorm-page aorm-page--roster-detail",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_components_RosterHeading__WEBPACK_IMPORTED_MODULE_3__["default"], {
      roster: roster
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.TabPanel, {
      className: "aorm-roster-tabs",
      tabs: [{
        name: 'assignment',
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Roster Assignment', 'wicket-aorm')
      }, {
        name: 'upload',
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Roster Upload', 'wicket-aorm')
      }, {
        name: 'activity',
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Roster Activity', 'wicket-aorm')
      }],
      children: tab => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)("div", {
        className: `aorm-tab aorm-tab--${tab.name}`,
        children: [tab.name === 'assignment' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_components_RosterAssignment__WEBPACK_IMPORTED_MODULE_4__["default"], {
          orgUuid: orgUuid,
          membershipUuid: membershipUuid
        }), tab.name === 'upload' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_components_RosterUpload__WEBPACK_IMPORTED_MODULE_5__["default"], {
          orgUuid: orgUuid,
          membershipUuid: membershipUuid
        }), tab.name === 'activity' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_components_RosterActivity__WEBPACK_IMPORTED_MODULE_6__["default"], {
          orgUuid: orgUuid,
          membershipUuid: membershipUuid
        })]
      })
    })]
  });
}

/***/ },

/***/ "./resources/js/utils/apiFetch.js"
/*!****************************************!*\
  !*** ./resources/js/utils/apiFetch.js ***!
  \****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   apiFetch: () => (/* binding */ apiFetch)
/* harmony export */ });
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/api-fetch */ "@wordpress/api-fetch");
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__);
/**
 * apiFetch wrapper.
 *
 * Configures @wordpress/api-fetch once with the nonce and REST root
 * injected by Assets.php, then re-exports it so all hooks import
 * from a single place.
 *
 * Assets.php calls wp_localize_script() to expose:
 *   window.aormContext.restUrl  — the WP REST root URL
 *   window.aormContext.nonce    — wp_create_nonce( 'wp_rest' )
 */


const context = window.aormContext ?? {};
if (context.nonce) {
  _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default().use(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default().createNonceMiddleware(context.nonce));
}
if (context.restUrl) {
  _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default().use(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default().createRootURLMiddleware(context.restUrl));
}

/**
 * Pre-configured apiFetch. Use exactly like @wordpress/api-fetch.
 *
 * @type {typeof import('@wordpress/api-fetch').default}
 */
const apiFetch = (_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default());

/***/ },

/***/ "./resources/css/roster-heading.css"
/*!******************************************!*\
  !*** ./resources/css/roster-heading.css ***!
  \******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ },

/***/ "react/jsx-runtime"
/*!**********************************!*\
  !*** external "ReactJSXRuntime" ***!
  \**********************************/
(module) {

module.exports = window["ReactJSXRuntime"];

/***/ },

/***/ "@wordpress/api-fetch"
/*!**********************************!*\
  !*** external ["wp","apiFetch"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["apiFetch"];

/***/ },

/***/ "@wordpress/components"
/*!************************************!*\
  !*** external ["wp","components"] ***!
  \************************************/
(module) {

module.exports = window["wp"]["components"];

/***/ },

/***/ "@wordpress/element"
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
(module) {

module.exports = window["wp"]["element"];

/***/ },

/***/ "@wordpress/i18n"
/*!******************************!*\
  !*** external ["wp","i18n"] ***!
  \******************************/
(module) {

module.exports = window["wp"]["i18n"];

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		if (!(moduleId in __webpack_modules__)) {
/******/ 			delete __webpack_module_cache__[moduleId];
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!*******************************!*\
  !*** ./resources/js/index.js ***!
  \*******************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _pages_OrgRosterDetail__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./pages/OrgRosterDetail */ "./resources/js/pages/OrgRosterDetail.js");
/* harmony import */ var _pages_GroupRosters__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./pages/GroupRosters */ "./resources/js/pages/GroupRosters.js");
/**
 * Plugin entry point.
 *
 * Mounts React islands into the div#aorm-* mount points rendered by MenuPage.php.
 * Each page gets its own independent React root so WP admin navigation works
 * without a full SPA router.
 *
 * Pages rendered via classic PHP (WP_List_Table or standard admin forms) do NOT
 * have a mount point here and never receive the React bundle:
 *   - Org memberships list  (AORM-3)  → WP_List_Table
 *   - Configurations                  → PHP admin page
 *   - Settings                        → PHP admin page
 *   - Global logs           (AORM-10) → WP_List_Table
 */





/**
 * Mount map: DOM element id → React component.
 */
const mounts = {
  'aorm-roster-detail': _pages_OrgRosterDetail__WEBPACK_IMPORTED_MODULE_1__["default"],
  'aorm-group-rosters': _pages_GroupRosters__WEBPACK_IMPORTED_MODULE_2__["default"]
};
Object.entries(mounts).forEach(([id, Component]) => {
  const el = document.getElementById(id);
  if (!el) {
    return;
  }
  const root = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createRoot)(el);
  root.render((0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createElement)(Component));
});
})();

/******/ })()
;
//# sourceMappingURL=index.js.map