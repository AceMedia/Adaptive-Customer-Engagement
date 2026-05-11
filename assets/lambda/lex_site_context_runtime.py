import json
import os
import re
import urllib.error
import urllib.request


def _load_snapshot():
    snapshot_path = os.path.join(os.path.dirname(__file__), "site_context_snapshot.json")
    try:
        with open(snapshot_path, "r", encoding="utf-8") as handle:
            data = json.load(handle)
            return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def _normalise_text(value):
    value = str(value or "").strip().lower()
    value = re.sub(r"\s+", " ", value)
    return value


def _extract_terms(value):
    terms = []
    for term in re.findall(r"[a-z0-9]{2,}", _normalise_text(value)):
        if term not in terms:
            terms.append(term)
    return terms


def _expand_query(query):
    expanded = query
    lowered = _normalise_text(query)
    if re.search(r"\b(what do you sell|what do u sell|what do you offer|products|services|what can you help with)\b", lowered):
        expanded += " products services solutions about"
    if re.search(r"\b(contact|phone|email|address|get in touch)\b", lowered):
        expanded += " contact phone email address"
    if re.search(r"\b(price|cost|pricing|quote)\b", lowered):
        expanded += " price pricing quote product"
    return expanded.strip()


def _score_document(document, terms):
    title = _normalise_text(document.get("title"))
    summary = _normalise_text(document.get("summary"))
    excerpt = _normalise_text(document.get("excerpt"))
    content = _normalise_text(document.get("content"))
    haystack = " ".join(filter(None, [title, summary, excerpt, content]))
    if not terms:
        return 1.0

    phrase = " ".join(terms).strip()
    score = 0.0
    if phrase and phrase in title:
        score += 14.0
    if phrase and phrase in haystack:
        score += 8.0

    for term in terms:
        if term in title:
            score += 5.0
        if term in summary:
            score += 3.0
        elif term in excerpt:
            score += 2.0
        elif term in content:
            score += 1.0

    if document.get("source_type") == "product":
        score += 0.5

    return score


def _answer_from_snapshot(question):
    snapshot = _load_snapshot()
    documents = snapshot.get("documents") or []
    if not isinstance(documents, list) or not documents:
        return {}

    site = snapshot.get("site") or {}
    question = (question or "").strip()
    lowered = _normalise_text(question)
    site_name = str(site.get("name") or os.environ.get("SITE_NAME") or "the site").strip()

    if not question:
        return {
            "answer": f"Hello, I am the {site_name} site assistant. Ask me about products, pages, posts, or contact details and I will look it up from the current site snapshot.",
            "fallback": False,
        }

    if re.search(r"^(hi|hello|hey|hiya|good morning|good afternoon|good evening)\b", lowered):
        return {
            "answer": f"Hello, I am the {site_name} site assistant. Ask me about products, pages, posts, or contact details and I will look it up from the current site snapshot.",
            "fallback": False,
        }

    terms = _extract_terms(_expand_query(question))
    ranked = []
    for document in documents:
        if not isinstance(document, dict):
            continue
        score = _score_document(document, terms)
        if score > 0:
            ranked.append((score, document))

    ranked.sort(key=lambda item: (item[0], item[1].get("modified_gmt") or ""), reverse=True)

    if not ranked:
        return {
            "answer": "I could not find a clear answer in the current site snapshot. Please try naming the page, post, or product you want to know about.",
            "fallback": True,
        }

    top = ranked[0][1]
    answer = f"I found this on {top.get('title') or 'the site'}: {(top.get('summary') or top.get('excerpt') or '').strip()}"

    related = []
    for _, document in ranked[1:3]:
        title = str(document.get("title") or "").strip()
        if title:
            related.append(title)

    if related:
        answer += " You may also want to look at " + ", ".join(related) + "."

    if len(answer) > 950:
        answer = answer[:947].rstrip(" \t\r\n.,;:") + "..."

    return {
        "answer": answer,
        "fallback": False,
        "sources": [
            {
                "id": top.get("id"),
                "title": top.get("title"),
                "url": top.get("url"),
                "source_type": top.get("source_type"),
                "source_label": top.get("source_label"),
                "summary": top.get("summary"),
            }
        ],
    }


def _get_input_text(event):
    if isinstance(event, dict):
        text = event.get("inputTranscript")
        if text:
            return str(text).strip()

    return ""


def _build_response(event, message):
    session_state = (event or {}).get("sessionState") or {}

    return {
        "sessionState": {
            "dialogAction": {
                "type": "ElicitIntent"
            },
            "sessionAttributes": session_state.get("sessionAttributes") or {}
        },
        "messages": [
            {
                "contentType": "PlainText",
                "content": message
            }
        ]
    }


def _call_site_context(question):
    endpoint = (os.environ.get("CHAT_API_ENDPOINT") or "").strip()
    secret = (os.environ.get("WEBHOOK_SECRET") or "").strip()

    if not endpoint or not secret:
        return {
            "answer": "The live site answer service is not configured yet.",
            "fallback": True,
        }

    payload = json.dumps({
        "query": question,
        "limit": 3,
    }).encode("utf-8")

    request = urllib.request.Request(
        endpoint,
        data=payload,
        headers={
            "Content-Type": "application/json",
            "X-ACE-Webhook-Secret": secret,
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            body = response.read().decode("utf-8")
            data = json.loads(body)
            return data if isinstance(data, dict) else {}
    except urllib.error.HTTPError as error:
        return {
            "answer": f"The live site answer service returned HTTP {error.code}.",
            "fallback": True,
        }
    except Exception:
        snapshot_answer = _answer_from_snapshot(question)
        if snapshot_answer:
            return snapshot_answer
        return {
            "answer": "The live site answer service could not be reached.",
            "fallback": True,
        }


def lambda_handler(event, context):
    question = _get_input_text(event)

    if not question:
        site_name = (os.environ.get("SITE_NAME") or "the site").strip()
        return _build_response(
            event,
            f"Hello, I am the {site_name} site assistant. Ask me about products, pages, posts, or contact details and I will look it up live."
        )

    data = _call_site_context(question)
    answer = (data.get("answer") or "I could not find a clear answer in the live site content.").strip()

    if len(answer) > 950:
        answer = answer[:947].rstrip(" \t\r\n.,;:") + "..."

    return _build_response(event, answer)
