//! Sharing levels, edit access and who may do what (RUB-438).
//!
//! Every Worker path that reads, serves, lists or edits a permanent artifact
//! decides visibility and permission here, so a rule changes in one place and
//! is tested once. Two properties matter more than anything else in this file:
//!
//! - **Fail closed.** A stored tier this file does not recognise is treated as
//!   [`Sharing::Private`]. Adding a tier later can never expose artifacts by
//!   default.
//! - **Org first.** Visibility is only ever decided for a credential of the
//!   artifact's own org. Callers resolve the org (and answer another org's
//!   artifact with the same 404 as a missing one) before asking anything here.
//!   The one deliberate cross-org case, Public + edit, is [`can_publish_version`]
//!   with `same_org = false`.

use serde::Serialize;

use super::auth::OrgCredential;

/// The scope Laravel puts on its own server-side reads (indexing, the console
/// open route's existence check). A credential carrying it sees private
/// artifacts, and its caller must apply [`can_view`] for the person it acts
/// for before showing or linking anything. It is never granted through OAuth:
/// the authorization server only issues the scopes of the user's role.
pub(crate) const READ_PRIVATE_SCOPE: &str = "artifacts:read_private";

/// Who can open an artifact. Stored in `artifacts.tier`: `private`, `secure`
/// (team) and `public`.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub(crate) enum Sharing {
    Private,
    Team,
    Public,
}

impl Sharing {
    /// The stored tier as a sharing level. Anything unrecognised, including
    /// `ephemeral` (which never has a D1 row) and a missing value, is private.
    pub(crate) fn from_tier(tier: &str) -> Self {
        match tier {
            "public" => Self::Public,
            "secure" => Self::Team,
            _ => Self::Private,
        }
    }

    /// Parses the API name (`private`, `team`, `public`).
    pub(crate) fn parse(value: &str) -> Option<Self> {
        match value {
            "private" => Some(Self::Private),
            "team" => Some(Self::Team),
            "public" => Some(Self::Public),
            _ => None,
        }
    }

    /// The value stored in `artifacts.tier`.
    pub(crate) fn tier(self) -> &'static str {
        match self {
            Self::Private => "private",
            Self::Team => "secure",
            Self::Public => "public",
        }
    }

    /// The API name.
    pub(crate) fn as_str(self) -> &'static str {
        match self {
            Self::Private => "private",
            Self::Team => "team",
            Self::Public => "public",
        }
    }

    /// Whether the artifact is served with no credential at all.
    pub(crate) fn is_anonymous(self) -> bool {
        self == Self::Public
    }
}

/// Whether the people an artifact is shared with may publish new versions.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub(crate) enum EditAccess {
    View,
    Edit,
}

impl EditAccess {
    /// The stored value; anything unrecognised is `view` (fail closed).
    pub(crate) fn from_stored(value: &str) -> Self {
        if value == "edit" {
            Self::Edit
        } else {
            Self::View
        }
    }

    pub(crate) fn parse(value: &str) -> Option<Self> {
        match value {
            "view" => Some(Self::View),
            "edit" => Some(Self::Edit),
            _ => None,
        }
    }

    pub(crate) fn as_str(self) -> &'static str {
        match self {
            Self::View => "view",
            Self::Edit => "edit",
        }
    }
}

/// The parts of a verified credential the decisions need. Built from
/// `auth::OrgCredential` at each call site.
#[derive(Clone, Copy, Debug)]
pub(crate) struct Viewer<'a> {
    pub(crate) user_id: &'a str,
    pub(crate) role: &'a str,
    /// The token's explicit `scope` claim; `None` means the role's defaults.
    pub(crate) scope: Option<&'a str>,
}

impl<'a> Viewer<'a> {
    /// Borrows the decision-relevant fields of a verified credential. The
    /// credential is the only source of user id, role and scope; callers may
    /// not pass one of these in from the request.
    pub(crate) fn from_credential(credential: &'a OrgCredential) -> Self {
        Self {
            user_id: &credential.user_id,
            role: &credential.role,
            scope: credential.scope.as_deref(),
        }
    }

    pub(crate) fn is_admin(&self) -> bool {
        self.role == "admin"
    }

    /// True only when the scope claim names [`READ_PRIVATE_SCOPE`] explicitly.
    /// A token with no scope claim does not get it by default.
    pub(crate) fn reads_private(&self) -> bool {
        self.scope.is_some_and(|scopes| {
            scopes
                .split_ascii_whitespace()
                .any(|scope| scope == READ_PRIVATE_SCOPE)
        })
    }

    fn owns(&self, owner_user_id: Option<&str>) -> bool {
        owner_user_id.is_some_and(|owner| !owner.is_empty() && owner == self.user_id)
    }
}

/// Whether a credential *of the artifact's own org* may see it.
///
/// Public and team artifacts are visible to every member. A private one is
/// visible to its owner, to team admins, and to Laravel's server-side reads
/// ([`READ_PRIVATE_SCOPE`]). A private artifact with no recorded owner is
/// visible to admins only.
pub(crate) fn can_view(sharing: Sharing, owner_user_id: Option<&str>, viewer: &Viewer<'_>) -> bool {
    match sharing {
        Sharing::Public | Sharing::Team => true,
        Sharing::Private => {
            viewer.owns(owner_user_id) || viewer.is_admin() || viewer.reads_private()
        }
    }
}

/// Whether an org-list row is visible to the caller. The same Private rule as
/// [`can_view`], but the list caller supplies the viewer's user id and whether
/// they may read every private artifact (a team admin, or a server-side read)
/// rather than a full [`Viewer`]. The list SQL filters on the literal
/// `private` value, which covers every tier the schema can store
/// (`tier TEXT NOT NULL DEFAULT 'public'`).
pub(crate) fn can_view_listing(
    sharing: Sharing,
    owner_user_id: Option<&str>,
    viewer_user_id: Option<&str>,
    viewer_reads_private: bool,
) -> bool {
    if viewer_reads_private {
        return true;
    }
    match sharing {
        Sharing::Private => owner_user_id.is_some_and(|owner| viewer_user_id == Some(owner)),
        Sharing::Team | Sharing::Public => true,
    }
}

/// Whether a credential may change sharing or edit access: the owner or a team
/// admin of the artifact's own org. A server-side read scope does not grant it.
pub(crate) fn can_change_sharing(
    owner_user_id: Option<&str>,
    viewer: &Viewer<'_>,
    same_org: bool,
) -> bool {
    same_org && (viewer.owns(owner_user_id) || viewer.is_admin())
}

/// Whether a credential may publish a new version (or restore one).
///
/// - The owner always may.
/// - An artifact with no recorded owner (published before owners existed and
///   not yet backfilled) may be versioned by any member of its org, as in
///   RUB-437.
/// - Team + edit: any member of the owning org.
/// - Public + edit: any signed-in Artfct account, including one from another
///   org (`same_org = false`). This is the only cross-org write; callers must
///   audit the editor's identity.
/// - Everything else is refused. Private + edit grants nothing beyond the
///   owner, because nobody else can see the artifact.
pub(crate) fn can_publish_version(
    sharing: Sharing,
    edit_access: EditAccess,
    owner_user_id: Option<&str>,
    viewer: &Viewer<'_>,
    same_org: bool,
) -> bool {
    if viewer.owns(owner_user_id) {
        return true;
    }
    if same_org && owner_user_id.is_none_or(str::is_empty) {
        return true;
    }
    match (sharing, edit_access) {
        (Sharing::Team, EditAccess::Edit) => same_org,
        (Sharing::Public, EditAccess::Edit) => true,
        _ => false,
    }
}

/// The sharing, edit-access and permission fields every artifact read returns
/// about the calling credential. Built once per row so metadata, the org list,
/// content reads and version responses agree on what the caller may do.
#[derive(Clone, Debug, Serialize)]
pub(crate) struct ArtifactAccess {
    pub(crate) sharing: Sharing,
    pub(crate) edit_access: EditAccess,
    pub(crate) owner_user_id: Option<String>,
    pub(crate) can_edit: bool,
    pub(crate) can_change_sharing: bool,
}

/// Computes the access fields from the stored sharing state and the calling
/// viewer. `same_org` is true for every read path here, which resolves the
/// artifact within the credential's own org first.
pub(crate) fn artifact_access(
    sharing: Sharing,
    edit_access: EditAccess,
    owner_user_id: Option<&str>,
    viewer: &Viewer<'_>,
    same_org: bool,
) -> ArtifactAccess {
    ArtifactAccess {
        sharing,
        edit_access,
        owner_user_id: owner_user_id.map(str::to_string),
        can_edit: can_publish_version(sharing, edit_access, owner_user_id, viewer, same_org),
        can_change_sharing: can_change_sharing(owner_user_id, viewer, same_org),
    }
}

/// Whether choosing `sharing` is allowed by the org's settings. Only public can
/// be switched off.
pub(crate) fn sharing_allowed(sharing: Sharing, public_sharing_allowed: bool) -> bool {
    sharing != Sharing::Public || public_sharing_allowed
}

#[cfg(test)]
mod tests {
    use super::*;

    fn member(user_id: &str) -> Viewer<'_> {
        Viewer {
            user_id,
            role: "member",
            scope: None,
        }
    }

    fn admin(user_id: &str) -> Viewer<'_> {
        Viewer {
            user_id,
            role: "admin",
            scope: None,
        }
    }

    #[test]
    fn unknown_tiers_are_private() {
        assert_eq!(Sharing::from_tier("public"), Sharing::Public);
        assert_eq!(Sharing::from_tier("secure"), Sharing::Team);
        assert_eq!(Sharing::from_tier("private"), Sharing::Private);
        for unknown in ["", "ephemeral", "Public", "SECURE", "team", "open"] {
            assert_eq!(Sharing::from_tier(unknown), Sharing::Private, "{unknown}");
            assert!(!Sharing::from_tier(unknown).is_anonymous(), "{unknown}");
        }
    }

    #[test]
    fn only_public_is_anonymous() {
        assert!(Sharing::Public.is_anonymous());
        assert!(!Sharing::Team.is_anonymous());
        assert!(!Sharing::Private.is_anonymous());
    }

    #[test]
    fn sharing_round_trips_between_api_and_storage() {
        for sharing in [Sharing::Private, Sharing::Team, Sharing::Public] {
            assert_eq!(Sharing::parse(sharing.as_str()), Some(sharing));
            assert_eq!(Sharing::from_tier(sharing.tier()), sharing);
        }
        assert_eq!(Sharing::parse("secure"), None);
        assert_eq!(Sharing::parse(""), None);
    }

    #[test]
    fn unknown_edit_access_is_view() {
        assert_eq!(EditAccess::from_stored("edit"), EditAccess::Edit);
        for value in ["", "view", "EDIT", "write"] {
            assert_eq!(EditAccess::from_stored(value), EditAccess::View, "{value}");
        }
    }

    #[test]
    fn private_is_visible_to_owner_admins_and_server_reads_only() {
        let owner = Some("7");
        assert!(can_view(Sharing::Private, owner, &member("7")));
        assert!(!can_view(Sharing::Private, owner, &member("8")));
        assert!(can_view(Sharing::Private, owner, &admin("8")));
        let viewer_role = Viewer {
            user_id: "8",
            role: "viewer",
            scope: None,
        };
        assert!(!can_view(Sharing::Private, owner, &viewer_role));
        let system = Viewer {
            user_id: "system",
            role: "member",
            scope: Some("artifacts:read artifacts:read_private"),
        };
        assert!(can_view(Sharing::Private, owner, &system));
    }

    #[test]
    fn the_system_user_id_alone_grants_nothing() {
        let named_system = Viewer {
            user_id: "system",
            role: "member",
            scope: None,
        };
        assert!(!can_view(Sharing::Private, Some("7"), &named_system));
        let near_miss = Viewer {
            user_id: "8",
            role: "member",
            scope: Some("artifacts:read artifacts:read_privatex"),
        };
        assert!(!can_view(Sharing::Private, Some("7"), &near_miss));
    }

    #[test]
    fn ownerless_private_is_admins_only() {
        assert!(!can_view(Sharing::Private, None, &member("7")));
        assert!(!can_view(Sharing::Private, Some(""), &member("")));
        assert!(can_view(Sharing::Private, None, &admin("8")));
    }

    #[test]
    fn team_and_public_are_visible_to_every_member() {
        for sharing in [Sharing::Team, Sharing::Public] {
            assert!(can_view(sharing, Some("7"), &member("8")));
            assert!(can_view(sharing, None, &member("8")));
        }
    }

    #[test]
    fn sharing_changes_are_owner_or_admin_of_the_same_org() {
        assert!(can_change_sharing(Some("7"), &member("7"), true));
        assert!(can_change_sharing(Some("7"), &admin("8"), true));
        assert!(!can_change_sharing(Some("7"), &member("8"), true));
        assert!(!can_change_sharing(Some("7"), &admin("8"), false));
        assert!(!can_change_sharing(Some("7"), &member("7"), false));
        assert!(!can_change_sharing(None, &member("7"), true));
        let system = Viewer {
            user_id: "system",
            role: "member",
            scope: Some(READ_PRIVATE_SCOPE),
        };
        assert!(!can_change_sharing(Some("7"), &system, true));
    }

    #[test]
    fn version_publishing_rules() {
        use EditAccess::{Edit, View};
        use Sharing::{Private, Public, Team};
        let owner = Some("7");
        // The owner always may, at every level.
        for sharing in [Private, Team, Public] {
            for edit in [View, Edit] {
                assert!(can_publish_version(
                    sharing,
                    edit,
                    owner,
                    &member("7"),
                    true
                ));
            }
        }
        // Someone else in the org.
        assert!(!can_publish_version(Team, View, owner, &member("8"), true));
        assert!(can_publish_version(Team, Edit, owner, &member("8"), true));
        assert!(!can_publish_version(
            Private,
            Edit,
            owner,
            &member("8"),
            true
        ));
        assert!(!can_publish_version(
            Private,
            Edit,
            owner,
            &admin("8"),
            true
        ));
        assert!(!can_publish_version(
            Public,
            View,
            owner,
            &member("8"),
            true
        ));
        assert!(can_publish_version(Public, Edit, owner, &member("8"), true));
        // Someone from another org: only public + edit.
        assert!(can_publish_version(
            Public,
            Edit,
            owner,
            &member("9"),
            false
        ));
        assert!(!can_publish_version(Team, Edit, owner, &member("9"), false));
        assert!(!can_publish_version(
            Public,
            View,
            owner,
            &member("9"),
            false
        ));
        // Ownerless legacy artifacts: any member of the same org, nobody outside it.
        assert!(can_publish_version(Team, View, None, &member("8"), true));
        assert!(!can_publish_version(Team, View, None, &member("9"), false));
        assert!(can_publish_version(Public, Edit, None, &member("9"), false));
    }

    #[test]
    fn list_visibility_matches_can_view() {
        // Public and team are visible to any member; private only to its owner
        // or a viewer with org-wide private reads.
        assert!(can_view_listing(Sharing::Public, None, Some("7"), false));
        assert!(can_view_listing(Sharing::Team, Some("8"), Some("7"), false));
        assert!(can_view_listing(
            Sharing::Private,
            Some("7"),
            Some("7"),
            false
        ));
        assert!(!can_view_listing(
            Sharing::Private,
            Some("8"),
            Some("7"),
            false
        ));
        assert!(!can_view_listing(Sharing::Private, None, Some("7"), false));
        assert!(can_view_listing(
            Sharing::Private,
            Some("8"),
            Some("7"),
            true
        ));
    }

    #[test]
    fn public_sharing_can_be_switched_off() {
        assert!(sharing_allowed(Sharing::Public, true));
        assert!(!sharing_allowed(Sharing::Public, false));
        assert!(sharing_allowed(Sharing::Team, false));
        assert!(sharing_allowed(Sharing::Private, false));
    }

    #[test]
    fn viewer_borrows_role_scope_and_user_from_the_credential() {
        let credential = OrgCredential {
            org_id: "acme".to_string(),
            user_id: "7".to_string(),
            role: "member".to_string(),
            scope: Some("artifacts:read artifacts:read_private".to_string()),
            token_id: "jti".to_string(),
        };
        let viewer = Viewer::from_credential(&credential);
        assert_eq!(viewer.user_id, "7");
        assert_eq!(viewer.role, "member");
        assert_eq!(viewer.scope, Some("artifacts:read artifacts:read_private"));
        // The borrowed scope is the one that grants private reads; nothing is
        // keyed on a user id string.
        assert!(viewer.reads_private());
        assert!(!viewer.is_admin());
    }

    #[test]
    fn access_fields_follow_the_same_decisions_as_the_rules() {
        let owner = Some("7");
        let member_owner = member("7");
        let member_other = member("8");
        let admin_other = admin("8");

        let own = artifact_access(
            Sharing::Private,
            EditAccess::View,
            owner,
            &member_owner,
            true,
        );
        assert!(own.can_edit);
        assert!(own.can_change_sharing);
        assert_eq!(own.sharing, Sharing::Private);
        assert_eq!(own.owner_user_id.as_deref(), owner);

        // A private artifact someone else cannot see grants nothing.
        let unseen = artifact_access(
            Sharing::Private,
            EditAccess::Edit,
            owner,
            &member_other,
            true,
        );
        assert!(!unseen.can_edit);
        assert!(!unseen.can_change_sharing);

        // Team + edit: another member may edit but not change sharing.
        let teammate = artifact_access(Sharing::Team, EditAccess::Edit, owner, &member_other, true);
        assert!(teammate.can_edit);
        assert!(!teammate.can_change_sharing);

        // An admin of the org may change sharing even when not the owner.
        let admin_view =
            artifact_access(Sharing::Team, EditAccess::View, owner, &admin_other, true);
        assert!(!admin_view.can_edit);
        assert!(admin_view.can_change_sharing);

        // Another org (same_org = false) never changes sharing, even for the
        // owner, and only public + edit may publish.
        let cross_org = artifact_access(
            Sharing::Public,
            EditAccess::Edit,
            owner,
            &member_owner,
            false,
        );
        assert!(cross_org.can_edit);
        assert!(!cross_org.can_change_sharing);
    }
}
