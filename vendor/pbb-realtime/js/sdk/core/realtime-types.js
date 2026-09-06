/**
 * @typedef {Object} RealtimeEnvelope
 * @property {string} namespace
 * @property {string} id
 * @property {string} type
 * @property {string} [room]
 * @property {object} [payload]
 * @property {object} [meta]
 *
 * @typedef {Object} RealtimeSessionClaims
 * @property {string} session_id
 * @property {string} user_id
 * @property {string} project_code
 * @property {string} app_code
 *
 * @typedef {Object} RealtimePresenceRosterEntry
 * @property {string} key
 * @property {string} sessionId
 * @property {string} userId
 * @property {string} displayName
 * @property {string} projectCode
 * @property {string} appCode
 * @property {string} state
 * @property {string} statusText
 * @property {string} updatedAt
 * @property {string} expiresAt
 *
 * @typedef {Object} RealtimeChatAttachment
 * @property {string} id
 * @property {string} kind
 * @property {string} name
 * @property {string} [url]
 * @property {string} [previewUrl]
 * @property {string} [posterUrl]
 * @property {string} [sizeLabel]
 * @property {string} [mimeType]
 * @property {number} [byteSize]
 * @property {string} [transfer_id]
 * @property {string} [attachment_id]
 *
 * @typedef {Object} RealtimeChatMessage
 * @property {string} id
 * @property {"incoming"|"outgoing"} direction
 * @property {string} senderName
 * @property {string} text
 * @property {string} timestamp
 * @property {string | undefined} [state]
 * @property {RealtimeChatAttachment[]} attachments
 * @property {{senderUserId:string}} meta
 *
 * @typedef {Object} RealtimeAttachmentPolicy
 * @property {number} maxAttachmentCount
 * @property {number} maxAttachmentBytes
 * @property {number} maxTotalBytesPerMessage
 * @property {number} chunkEventsPerMinute
 * @property {number} chunkBytesPerMinute
 *
 * @typedef {Object} RealtimeCallSignalOptions
 * @property {string} [targetUserId]
 * @property {string | null} [sdp]
 * @property {object | null} [candidate]
 * @property {object | null} [meta]
 */

export {};
