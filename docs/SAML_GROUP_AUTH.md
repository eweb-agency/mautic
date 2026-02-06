# SAML group-gated authentication

Ce projet ajoute un verrou SAML basé sur un groupe racine obligatoire. L’authentification échoue (403) si le groupe attendu n’est pas présent dans l’assertion SAML. Renseigne au minimum `saml_required_group_id` (ou `MAUTIC_SAML_REQUIRED_GROUP_ID`).

## Claims attendus
- Groupe (par défaut claim `Group`) : valeurs de type `/org_id`, `/org_id/roles/<role>`.
- Rôle (par défaut claim `Role`) : valeurs au format `/org_id/roles/<role>` (optionnel).
- Le groupe racine obligatoire est défini par `saml_required_group_id` (ex: `57f5ac2a-b3ca-4af6-864e-50ce800ede08`).

## Variables/paramètres
- `saml_required_group_id` (obligatoire) : UUID du groupe racine attendu (avec ou sans slash en entrée).
- `saml_group_attribute` (par défaut `Group`) : nom du claim qui contient les groupes.
- `saml_role_attribute` (par défaut `Role`) : nom du claim qui contient les rôles.
- `saml_idp_default_role` : rôle Mautic à affecter si aucun rôle SAML mappable n’est trouvé. Si ce champ est vide et qu’aucun rôle SAML mappable n’est présent, l’auth est refusée.
- Environnement : `MAUTIC_SAML_REQUIRED_GROUP_ID`, `MAUTIC_SAML_GROUP_ATTRIBUTE`, `MAUTIC_SAML_ROLE_ATTRIBUTE` (renseignés via la config ou `.env`).

## Comportement
1. Le mapper SAML vérifie la présence du groupe racine et extrait éventuellement un rôle `/org_id/roles/<role>` pour le même `org_id`.
2. Si le groupe est absent/invalide, l’authentification est refusée immédiatement (403 sur `/s/saml/login_check`).
3. Lors de la création d’un utilisateur :
   - Si un rôle SAML est trouvé, on cherche un rôle Mautic du même nom (insensible à la casse).
   - Si aucun rôle SAML mappable n’est trouvé, on affecte `saml_idp_default_role`.
   - Si aucun rôle n’est disponible (pas de SAML role mappable et pas de default), l’auth est refusée.

## Pré-requis dans Mautic
- Créer le rôle par défaut indiqué par `saml_idp_default_role` (et, si souhaité, les rôles Mautic qui correspondent aux noms envoyés par l’IdP).
- Configurer `saml_required_group_id` avec l’UUID racine fourni par l’IdP.
- Aligner les claims côté IdP :
  - `Group` contient `/org_id` et éventuellement `/org_id/roles/<role>`
  - `Role` contient éventuellement `/org_id/roles/<role>`

## Flux simplifié
1. L’utilisateur s’authentifie sur l’IdP.
2. L’assertion SAML est reçue sur `/s/saml/login_check`.
3. Le groupe racine est validé; un rôle SAML est utilisé s’il correspond à un rôle Mautic, sinon le rôle par défaut est appliqué.
4. Si aucun rôle ne peut être appliqué, la réponse est `403 Forbidden`.
